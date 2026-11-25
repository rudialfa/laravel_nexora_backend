<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanKeuangan;
use Tests\TestCase;

/**
 * Menguji saldo, mutasi, dan penghasilan per pesanan untuk seller lewat HTTP.
 */
class WalletApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanKeuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturPengiriman();
    }

    public function test_endpoint_keuangan_butuh_login_akun_aktif_dan_peran_seller(): void
    {
        $u = $this->uuidAsing();

        $rute = [
            ['GET', '/api/v1/seller/wallet'],
            ['GET', '/api/v1/seller/wallet/transactions'],
            ['GET', "/api/v1/seller/orders/{$u}/earnings"],
        ];

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif] = $this->pembeli('suspended');
        [, $biasa]    = $this->pembeli();

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
            $this->kirim($method, $uri, [], $biasa)->assertStatus(403);
        }
    }

    public function test_seller_melihat_saldo_dan_mutasi_tokonya_sendiri_dengan_filter_dan_paginasi(): void
    {
        [,, $grup, $o, $w] = $this->pesananBelumBayar();
        [$admin]            = $this->akunAdmin();

        $this->lunas($grup);
        $w->fresh()->adjust(100000, $admin, 'Bonus uji');

        $token = $this->penjual($o);

        $this->kirim('GET', '/api/v1/seller/wallet', [], $token)
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.available', 100000)
            ->assertJsonPath('data.pending', 190000)
            ->assertJsonPath('data.total', 290000)
            ->assertJsonPath('data.min_withdrawal', 50000)
            ->assertJsonPath('data.max_pending_withdrawals', 3)
            ->assertJsonPath('data.withdrawals_pending_count', 0)
            ->assertJsonPath('data.withdrawals_pending_amount', 0);

        $res = $this->kirim('GET', '/api/v1/seller/wallet/transactions', [], $token)->assertOk()->assertJson(['success' => true]);

        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame(['adjustment', 'sale_hold'], collect($res->json('data'))->pluck('type')->all());
        $this->assertSame(['available', 100000, 100000, null], [
            $res->json('data.0.bucket'),
            $res->json('data.0.amount'),
            $res->json('data.0.balance_after'),
            $res->json('data.0.reference'),
        ]);
        $this->assertSame(['order', $o->number, 190000], [
            $res->json('data.1.reference.type'),
            $res->json('data.1.reference.number'),
            $res->json('data.1.amount'),
        ]);

        // Filter dan paginasi.
        $this->assertCount(1, $this->kirim('GET', '/api/v1/seller/wallet/transactions?bucket=available', [], $token)->assertOk()->json('data'));
        $this->assertSame('sale_hold', $this->kirim('GET', '/api/v1/seller/wallet/transactions?type=sale_hold', [], $token)->assertOk()->json('data.0.type'));

        $hal2 = $this->kirim('GET', '/api/v1/seller/wallet/transactions?per_page=1&page=2', [], $token)->assertOk();

        $this->assertSame([2, 2], [$hal2->json('meta.total'), $hal2->json('meta.last_page')]);
        $this->assertSame('sale_hold', $hal2->json('data.0.type'));

        foreach (['?bucket=x', '?type=x', '?per_page=100', '?per_page=0', '?page=0'] as $salah) {
            $this->kirim('GET', "/api/v1/seller/wallet/transactions{$salah}", [], $token)->assertStatus(422);
        }

        // Seller lain tidak melihat dana ini.
        [,,, $o2] = $this->pesananBelumBayar();
        $tokenLain  = $this->penjual($o2);

        $this->kirim('GET', '/api/v1/seller/wallet', [], $tokenLain)
            ->assertOk()->assertJsonPath('data.available', 0)->assertJsonPath('data.pending', 0);
        $this->assertSame([], $this->kirim('GET', '/api/v1/seller/wallet/transactions', [], $tokenLain)->assertOk()->json('data'));
    }

    public function test_penghasilan_per_pesanan_menjelaskan_rincian_dan_status_dana(): void
    {
        [$user,, $grup, $o] = $this->pesananBelumBayar();

        $v = new Voucher;
        $v->forceFill([
            'store_id'       => $o->store_id,
            'code'           => 'TOKOV2',
            'name'           => 'Voucher toko',
            'discount_type'  => 'fixed',
            'discount_value' => 15000,
            'min_purchase'   => 0,
            'per_user_limit' => 1,
            'is_active'      => true,
        ])->save();
        $v->fresh()->redeem($user, $grup, 200000);

        $o     = $this->lunas($grup);
        $token = $this->penjual($o);
        $url   = "/api/v1/seller/orders/{$o->uuid}/earnings";

        $res = $this->kirim('GET', $url, [], $token)
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.order.number', $o->number)
            ->assertJsonPath('data.subtotal', 200000)
            ->assertJsonPath('data.store_voucher_discount', 15000)
            ->assertJsonPath('data.commission_amount', 10000)
            ->assertJsonPath('data.net_income', 175000)
            ->assertJsonPath('data.shipping_charge', 0)
            ->assertJsonPath('data.funds', 'held');

        $this->assertEquals(5, $res->json('data.commission_rate'));

        // Ada selisih ongkir: aktual 30.000, dibayar pembeli 20.000, platform menanggung 5.000.
        $s = new Shipment(['order_id' => $o->id, 'courier_price' => 30000]);
        $s->save();

        $this->kirim('GET', $url, [], $token)->assertOk()->assertJsonPath('data.shipping_charge', 5000);

        $this->selesaikan($o);

        $this->kirim('GET', $url, [], $token)->assertOk()->assertJsonPath('data.funds', 'released')->assertJsonPath('data.order.status', 'completed');

        // Pesanan belum dibayar, milik toko lain, dan tidak ada: 404.
        [,,, $belum] = $this->pesananBelumBayar();
        $tokenLain     = $this->penjual($belum);

        $this->kirim('GET', "/api/v1/seller/orders/{$belum->uuid}/earnings", [], $tokenLain)->assertStatus(404);
        $this->kirim('GET', $url, [], $tokenLain)->assertStatus(404);
        $this->kirim('GET', "/api/v1/seller/orders/{$this->uuidAsing()}/earnings", [], $token)->assertStatus(404);
    }
}
