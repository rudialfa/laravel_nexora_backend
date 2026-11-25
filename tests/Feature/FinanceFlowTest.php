<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\Voucher;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanKeuangan;
use Tests\TestCase;

/**
 * Menguji pergerakan dompet toko mengikuti status pesanan.
 */
class FinanceFlowTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanKeuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturPengiriman();
    }

    public function test_pesanan_toko_tanpa_dompet_tetap_berjalan_dan_dompet_tidak_dibuat_sendiri(): void
    {
        [$user] = $this->pembeli();
        $o      = $this->lunas($this->checkout($user));

        $this->assertSame('paid', $o->status);

        $this->selesaikan($o);

        $this->assertSame('completed', $o->fresh()->status);
        $this->assertSame(0, Wallet::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_pembayaran_menahan_penghasilan_bersih_voucher_toko_mengurangi_dan_voucher_platform_tidak(): void
    {
        [$user,, $grup, $o, $w] = $this->pesananBelumBayar();

        $v = new Voucher;
        $v->forceFill([
            'store_id'       => $o->store_id,
            'code'           => 'TOKOV1',
            'name'           => 'Voucher toko',
            'discount_type'  => 'fixed',
            'discount_value' => 15000,
            'min_purchase'   => 0,
            'per_user_limit' => 1,
            'is_active'      => true,
        ])->save();
        $v->fresh()->redeem($user, $grup, 200000);

        // Belum dibayar: belum ada dana.
        $this->assertSame(0, WalletTransaction::count());

        $this->lunas($grup);

        // 200.000 - voucher toko 15.000 - komisi 10.000; voucher platform 10.000 tidak ikut dipotong.
        $w = $w->fresh();

        $this->assertSame([0, 175000], [(int) $w->balance, (int) $w->pending_balance]);
        $this->assertSame(1, WalletTransaction::count());

        $t = WalletTransaction::first();

        $this->assertSame(['pending', 'sale_hold', 175000, 175000], [$t->bucket, $t->type, (int) $t->amount, (int) $t->balance_after]);
        $this->assertSame($o->id, (int) $t->reference_id);
        $this->assertSame($o->getMorphClass(), $t->reference_type);
    }

    public function test_pesanan_selesai_mencairkan_dana_dan_pemrosesan_ulang_tidak_menggandakan(): void
    {
        [,, $grup, $o, $w] = $this->pesananBelumBayar();

        $o = $this->lunas($grup);

        $this->assertSame(190000, (int) $w->fresh()->pending_balance);

        $this->selesaikan($o);

        $w = $w->fresh();

        $this->assertSame([190000, 0], [(int) $w->balance, (int) $w->pending_balance]);
        $this->assertSame(['sale_hold', 'sale_release', 'sale_release'], WalletTransaction::orderBy('id')->pluck('type')->all());

        // Riwayat "selesai" diproses lagi: tidak ada yang berubah.
        $riwayat = $o->fresh()->statusHistories()->reorder()->orderByDesc('id')->first();

        $this->assertSame('completed', $riwayat->to_status);

        app(FinanceService::class)->handleStatusChange($riwayat);

        $this->assertSame(3, WalletTransaction::count());
        $this->assertSame(190000, (int) $w->fresh()->balance);
    }

    public function test_selisih_ongkir_dipotong_dengan_batas_dan_tidak_ada_potongan_jika_aktual_lebih_murah(): void
    {
        // Ongkir yang dibayar pembeli 20.000 dan platform menanggung selisih sampai 5.000.
        // Harga aktual => potongan seller.
        $kasus = [30000 => 5000, 18000 => 0, 500000 => 190000];

        foreach ($kasus as $aktual => $potongan) {
            [,, $grup,, $w] = $this->pesananBelumBayar();

            $o = $this->lunas($grup);

            $s = new Shipment(['order_id' => $o->id, 'courier_price' => $aktual]);
            $s->save();

            $this->selesaikan($o);

            $w = $w->fresh();

            $this->assertSame(190000 - $potongan, (int) $w->balance, "aktual {$aktual}");
            $this->assertSame(0, (int) $w->pending_balance, "aktual {$aktual}");

            $baris = WalletTransaction::where('wallet_id', $w->id)->where('type', 'shipping_difference')->get();

            if ($potongan > 0) {
                $this->assertCount(1, $baris, "aktual {$aktual}");
                $this->assertSame(-$potongan, (int) $baris[0]->amount);
                $this->assertSame('available', $baris[0]->bucket);
            } else {
                $this->assertCount(0, $baris, "aktual {$aktual}");
            }
        }
    }

    public function test_pembatalan_setelah_dibayar_membalik_dana_dan_checkout_belum_dibayar_tidak_menyentuh_dompet(): void
    {
        [,, $grup, $o, $w] = $this->pesananBelumBayar();

        $o     = $this->lunas($grup);
        $token = $this->penjual($o);

        $this->assertSame(190000, (int) $w->fresh()->pending_balance);

        $this->kirim('POST', "/api/v1/seller/orders/{$o->uuid}/cancel", ['reason' => 'Barang rusak saat dikemas'], $token)
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $w = $w->fresh();

        $this->assertSame([0, 0], [(int) $w->balance, (int) $w->pending_balance]);
        $this->assertSame(['sale_hold', 'sale_reversal'], WalletTransaction::where('wallet_id', $w->id)->orderBy('id')->pluck('type')->all());
        $this->assertSame(-190000, (int) WalletTransaction::where('type', 'sale_reversal')->value('amount'));

        // Checkout yang belum dibayar lalu dibatalkan pembeli: dompet tidak tersentuh.
        [, $token2,, $o2, $w2] = $this->pesananBelumBayar();

        $this->kirim('POST', "/api/v1/orders/{$o2->uuid}/cancel", [], $token2)->assertOk();

        $this->assertSame('cancelled', $o2->fresh()->status);
        $this->assertSame(0, WalletTransaction::where('wallet_id', $w2->id)->count());
        $this->assertSame([0, 0], [(int) $w2->fresh()->balance, (int) $w2->fresh()->pending_balance]);
    }

    public function test_pesanan_yang_dibayar_sebelum_ada_dompet_tetap_dicatat_lengkap_saat_selesai(): void
    {
        [$user] = $this->pembeli();

        $grup  = $this->checkout($user);
        $order = Order::where('checkout_group_id', $grup->id)->first();
        $order->forceFill(['commission_rate' => 5, 'commission_amount' => 10000])->save();

        $o = $this->lunas($grup);   // dompet belum ada

        $this->assertSame(0, WalletTransaction::count());

        $w = Wallet::firstOrCreate(['store_id' => $o->store_id])->fresh();

        $this->selesaikan($o);

        $w = $w->fresh();

        $this->assertSame([190000, 0], [(int) $w->balance, (int) $w->pending_balance]);
        $this->assertSame(['sale_hold', 'sale_release', 'sale_release'], WalletTransaction::orderBy('id')->pluck('type')->all());
    }
}
