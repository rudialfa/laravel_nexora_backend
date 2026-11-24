<?php

namespace Tests\Feature;

use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SiapkanPembayaran;
use Tests\TestCase;

/**
 * Menguji alur pesanan (pembeli dan seller) lewat HTTP.
 */
class OrderApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanPembayaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->aturMidtrans();
    }

    // ----------------------------------------------------------------- BANTUAN

    /** Menandai seluruh pesanan checkout sebagai lunas (seperti notifikasi Midtrans). Mengembalikan pesanan pertama. */
    private function lunas(CheckoutGroup $grup): Order
    {
        foreach (Order::where('checkout_group_id', $grup->id)->orderBy('id')->get() as $order) {
            $order->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM);
        }

        $grup->forceFill(['status' => CheckoutGroup::STATUS_PAID, 'paid_at' => now()])->save();

        return Order::where('checkout_group_id', $grup->id)->orderBy('id')->first();
    }

    private function pesanan(CheckoutGroup $grup): Order
    {
        return Order::where('checkout_group_id', $grup->id)->orderBy('id')->first();
    }

    /** Token seller pemilik toko pesanan ini. */
    private function penjual(Order $order): string
    {
        $toko = $order->store;
        $toko->forceFill(['verified_at' => now()])->save();

        $pemilik = $toko->owner;

        if (! $pemilik->hasRole('seller')) {
            $pemilik->assignRole('seller');
        }

        return $pemilik->createToken('test', ['*'], now()->addDay())->plainTextToken;
    }

    private function nomor($res): array
    {
        return collect($res->json('data'))->pluck('number')->all();
    }

    // -------------------------------------------------------------------- TES

    public function test_endpoint_pesanan_butuh_login_akun_aktif_dan_peran_yang_sesuai(): void
    {
        $u = $this->uuidAsing();

        $pembeli = [
            ['GET', '/api/v1/orders'],
            ['GET', "/api/v1/orders/{$u}"],
            ['GET', "/api/v1/orders/{$u}/invoice"],
            ['POST', "/api/v1/orders/{$u}/cancel"],
            ['POST', "/api/v1/orders/{$u}/complete"],
        ];
        $penjual = [
            ['GET', '/api/v1/seller/orders'],
            ['GET', "/api/v1/seller/orders/{$u}"],
            ['POST', "/api/v1/seller/orders/{$u}/process"],
            ['POST', "/api/v1/seller/orders/{$u}/pack"],
            ['POST', "/api/v1/seller/orders/{$u}/cancel"],
        ];

        foreach (array_merge($pembeli, $penjual) as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif] = $this->pembeli('suspended');

        foreach (array_merge($pembeli, $penjual) as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
        }

        [, $biasa] = $this->pembeli();

        foreach ($penjual as [$method, $uri]) {
            $this->kirim($method, $uri, [], $biasa)->assertStatus(403);
        }
    }

    public function test_pembeli_melihat_daftar_pesanannya_dengan_filter_dan_paginasi(): void
    {
        [$a, $tokenA] = $this->pembeli();
        [$b, $tokenB] = $this->pembeli();

        $g1 = $this->checkout($a);
        $g2 = $this->checkout($a);
        $g3 = $this->checkout($b);

        $o2 = $this->lunas($g2);
        $o1 = $this->pesanan($g1);
        $o3 = $this->pesanan($g3);

        $res = $this->kirim('GET', '/api/v1/orders', [], $tokenA)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$o2->number, $o1->number], $this->nomor($res));
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame(2, $res->json('data.0.items_count'));
        $this->assertSame('paid', $res->json('data.0.status'));
        $this->assertSame(210000, $res->json('data.0.grand_total'));

        // Pembeli lain hanya melihat miliknya.
        $this->assertSame([$o3->number], $this->nomor($this->kirim('GET', '/api/v1/orders', [], $tokenB)->assertOk()));

        // Filter status dan pencarian (huruf kecil cocok, karakter khusus apa adanya).
        $this->assertSame([$o2->number], $this->nomor($this->kirim('GET', '/api/v1/orders?status=paid', [], $tokenA)->assertOk()));
        $this->assertSame([$o1->number], $this->nomor($this->kirim('GET', '/api/v1/orders?q=' . strtolower(substr($g1->number, -5)), [], $tokenA)->assertOk()));
        $this->assertSame([], $this->nomor($this->kirim('GET', '/api/v1/orders?q=' . urlencode('%'), [], $tokenA)->assertOk()));
        $this->assertSame([], $this->nomor($this->kirim('GET', '/api/v1/orders?q=' . urlencode('_'), [], $tokenA)->assertOk()));

        // Paginasi.
        $hal2 = $this->kirim('GET', '/api/v1/orders?per_page=1&page=2', [], $tokenA)->assertOk();

        $this->assertSame([$o1->number], $this->nomor($hal2));
        $this->assertSame([2, 1, 2], [$hal2->json('meta.last_page'), $hal2->json('meta.per_page'), $hal2->json('meta.current_page')]);

        foreach (['?status=abc', '?per_page=100', '?per_page=0', '?page=0', '?q=' . str_repeat('a', 101)] as $salah) {
            $this->kirim('GET', "/api/v1/orders{$salah}", [], $tokenA)->assertStatus(422);
        }
    }

    public function test_detail_pesanan_pembeli_lengkap_tanpa_data_pribadi_dan_milik_orang_lain_404(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();

        $grup  = $this->checkout($user);
        $o     = $this->pesanan($grup);
        $item  = OrderItem::first();
        $url   = "/api/v1/orders/{$o->uuid}";

        $res = $this->kirim('GET', $url, [], $token)
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.id', $o->uuid)
            ->assertJsonPath('data.number', $o->number)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.grand_total', 210000)
            ->assertJsonPath('data.items.0.product_name', $item->product_name)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', 100000)
            ->assertJsonPath('data.shipping_address.street', 'Jl. Contoh No. 1')
            ->assertJsonPath('data.timeline', [])
            ->assertJsonPath('data.shipment', null)
            ->assertJsonPath('data.actions.cancel', true)
            ->assertJsonPath('data.actions.complete', false)
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.shipping_address.biteship_area_id');

        $this->assertStringNotContainsString($user->email, $res->getContent());

        // Setelah dibayar, pembatalan tidak lagi ditawarkan dan riwayat terisi.
        $this->lunas($grup);

        $this->kirim('GET', $url, [], $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.actions.cancel', false)
            ->assertJsonPath('data.timeline.0.to_status', 'paid')
            ->assertJsonPath('data.timeline.0.actor_type', 'system');

        $this->kirim('GET', $url, [], $tokenLain)->assertStatus(404);
        $this->kirim('GET', '/api/v1/orders/' . $this->uuidAsing(), [], $token)->assertStatus(404);
        $this->kirim('GET', '/api/v1/orders/abc', [], $token)->assertStatus(404);
    }

    public function test_seller_hanya_melihat_pesanan_tokonya_yang_sudah_dibayar(): void
    {
        [$u1] = $this->pembeli();
        [$u2] = $this->pembeli();

        $g1 = $this->checkout($u1);
        $g2 = $this->checkout($u2);

        $o1 = $this->pesanan($g1);
        $o2 = $this->lunas($g2);

        $tokenS1 = $this->penjual($o1);
        $tokenS2 = $this->penjual($o2);

        // Pesanan belum dibayar tidak terlihat oleh seller.
        $this->assertSame([], $this->nomor($this->kirim('GET', '/api/v1/seller/orders', [], $tokenS1)->assertOk()));
        $this->kirim('GET', "/api/v1/seller/orders/{$o1->uuid}", [], $tokenS1)->assertStatus(404);

        $o1 = $this->lunas($g1);

        $res = $this->kirim('GET', '/api/v1/seller/orders', [], $tokenS1)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$o1->number], $this->nomor($res));

        $this->kirim('GET', "/api/v1/seller/orders/{$o1->uuid}", [], $tokenS1)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.shipping_address.street', 'Jl. Contoh No. 1')
            ->assertJsonPath('data.actions.process', true)
            ->assertJsonPath('data.actions.pack', false)
            ->assertJsonPath('data.actions.cancel', true);

        // Pesanan toko lain dijawab 404, dan daftar seller lain hanya miliknya.
        $this->kirim('GET', "/api/v1/seller/orders/{$o2->uuid}", [], $tokenS1)->assertStatus(404);
        $this->assertSame([$o2->number], $this->nomor($this->kirim('GET', '/api/v1/seller/orders', [], $tokenS2)->assertOk()));
        $this->kirim('GET', '/api/v1/seller/orders/' . $this->uuidAsing(), [], $tokenS1)->assertStatus(404);
    }

    public function test_seller_memproses_lalu_mengemas_pesanan_dan_urutan_status_dijaga(): void
    {
        [$user] = $this->pembeli();
        [$lain] = $this->pembeli();

        $o      = $this->lunas($this->checkout($user));
        $oLain  = $this->lunas($this->checkout($lain));
        $token  = $this->penjual($o);
        $base   = "/api/v1/seller/orders/{$o->uuid}";

        // Mengemas sebelum diproses ditolak.
        $this->kirim('POST', "{$base}/pack", [], $token)->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors(['order']);

        $this->kirim('POST', "{$base}/process", [], $token)
            ->assertOk()
            ->assertJson(['message' => 'Pesanan diproses.'])
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.actions.process', false)
            ->assertJsonPath('data.actions.pack', true);

        $this->kirim('POST', "{$base}/process", [], $token)->assertStatus(422)->assertJsonValidationErrors(['order']);

        $this->kirim('POST', "{$base}/pack", [], $token)
            ->assertOk()
            ->assertJson(['message' => 'Pesanan ditandai sudah dikemas.'])
            ->assertJsonPath('data.status', 'packed');

        $this->kirim('POST', "{$base}/pack", [], $token)->assertStatus(422);

        $res = $this->kirim('GET', $base, [], $token)->assertOk();

        $this->assertCount(3, $res->json('data.timeline'));
        $this->assertSame(['paid', 'processing', 'packed'], collect($res->json('data.timeline'))->pluck('to_status')->all());
        $this->assertSame('seller', $res->json('data.timeline.2.actor_type'));

        // Seller lain dan pembeli biasa tidak bisa menyentuhnya.
        $this->kirim('POST', "/api/v1/seller/orders/{$oLain->uuid}/process", [], $token)->assertStatus(404);
        $this->assertSame('paid', $oLain->fresh()->status);
    }

    public function test_seller_membatalkan_pesanan_dengan_alasan_stok_kembali_dan_pengembalian_dana_tercatat(): void
    {
        [$user] = $this->pembeli();

        $grup  = $this->checkout($user);
        $o     = $this->lunas($grup);
        $token = $this->penjual($o);
        $url   = "/api/v1/seller/orders/{$o->uuid}/cancel";
        $varian = ProductVariant::find(OrderItem::where('order_id', $o->id)->value('variant_id'));

        $this->assertSame(8, (int) $varian->stock);

        // Alasan wajib.
        $this->kirim('POST', $url, [], $token)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->kirim('POST', $url, ['reason' => 'abc'], $token)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->kirim('POST', $url, ['reason' => str_repeat('a', 201)], $token)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertSame('paid', $o->fresh()->status);

        $this->kirim('POST', $url, ['reason' => 'Stok rusak saat pengemasan'], $token)
            ->assertOk()
            ->assertJson(['message' => 'Pesanan dibatalkan. Pengembalian dana dicatat untuk pembeli.'])
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.actions.cancel', false);

        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertNotNull($o->fresh()->cancelled_at);
        $this->assertSame(10, (int) $varian->fresh()->stock);

        // Satu-satunya pesanan di checkout: biaya layanan ikut dikembalikan.
        $this->assertSame(1, Refund::count());

        $refund = Refund::first();

        $this->assertSame($o->id, (int) $refund->order_id);
        $this->assertSame(211000, (int) $refund->amount);
        $this->assertSame('pending', $refund->status);
        $this->assertSame('manual', $refund->method);
        $this->assertStringContainsString('Stok rusak', (string) $refund->reason);

        $riwayat = $o->fresh()->statusHistories()->reorder()->orderByDesc('id')->first();

        $this->assertSame('cancelled', $riwayat->to_status);
        $this->assertSame('seller', $riwayat->actor_type);
        $this->assertStringContainsString('Stok rusak', (string) $riwayat->note);

        // Dibatalkan dua kali ditolak dan tidak menggandakan apa pun.
        $this->kirim('POST', $url, ['reason' => 'Stok rusak saat pengemasan'], $token)->assertStatus(422)->assertJsonValidationErrors(['order']);
        $this->assertSame(1, Refund::count());
        $this->assertSame(10, (int) $varian->fresh()->stock);

        // Pesanan yang sudah dikirim tidak bisa dibatalkan seller.
        $g2 = $this->checkout($user);
        $o2 = $this->lunas($g2);
        $o2->transitionTo(Order::STATUS_PROCESSING, Order::ACTOR_SELLER);
        $o2->transitionTo(Order::STATUS_PACKED, Order::ACTOR_SELLER);
        $o2->transitionTo(Order::STATUS_SHIPPED, Order::ACTOR_SYSTEM);

        $this->kirim('POST', "/api/v1/seller/orders/{$o2->uuid}/cancel", ['reason' => 'Ingin membatalkan saja'], $this->penjual($o2))
            ->assertStatus(422)->assertJsonValidationErrors(['order']);

        $this->assertSame('shipped', $o2->fresh()->status);
        $this->assertSame(1, Refund::count());
    }

    public function test_biaya_layanan_dikembalikan_hanya_saat_seluruh_pesanan_dalam_checkout_batal(): void
    {
        [$user] = $this->pembeli();

        $grup = $this->checkout($user);
        $o1   = $this->lunas($grup);

        // Pesanan kedua di checkout yang sama (toko yang sama agar token seller sama).
        $o2 = $o1->replicate(['uuid']);
        $o2->forceFill(['number' => Order::generateNumber($grup, 2)])->save();

        $token = $this->penjual($o1);

        $this->kirim('POST', "/api/v1/seller/orders/{$o1->uuid}/cancel", ['reason' => 'Barang tidak tersedia'], $token)->assertOk();

        // Masih ada pesanan lain yang aktif: hanya total pesanan yang dikembalikan.
        $this->assertSame(210000, (int) Refund::where('order_id', $o1->id)->value('amount'));

        $this->kirim('POST', "/api/v1/seller/orders/{$o2->uuid}/cancel", ['reason' => 'Barang tidak tersedia'], $token)->assertOk();

        // Pembatalan terakhir: biaya layanan ikut, tepat satu kali.
        $this->assertSame(211000, (int) Refund::where('order_id', $o2->id)->value('amount'));
        $this->assertSame(2, Refund::count());
        $this->assertSame(421000, (int) Refund::sum('amount'));
    }

    public function test_pembeli_membatalkan_pesanan_belum_dibayar_menutup_seluruh_checkout(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();

        $grup = $this->checkout($user);
        $this->palsuMidtrans();
        $this->bayar($grup, $token)->assertStatus(201);

        $o   = $this->pesanan($grup);
        $url = "/api/v1/orders/{$o->uuid}/cancel";

        $this->kirim('POST', $url, [], $tokenLain)->assertStatus(404);

        $this->kirim('POST', $url, [], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Checkout dibatalkan. Seluruh pesanan dalam checkout ini ikut dibatalkan.'])
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $grup->fresh()->status);
        $this->assertSame(10, (int) ProductVariant::first()->stock);
        $this->assertSame('released', VoucherUsage::first()->status);
        $this->assertSame(0, (int) Voucher::first()->used_count);
        $this->assertSame('cancelled', Payment::first()->status);

        // Tagihan yang masih menunggu diminta kedaluwarsa di Midtrans.
        Http::assertSent(fn(Request $r) => str_ends_with($r->url(), '/' . $grup->number . '-1/expire'));

        // Dibatalkan dua kali ditolak.
        $this->kirim('POST', $url, [], $token)->assertStatus(422)->assertJsonValidationErrors(['order']);

        // Pesanan yang sudah dibayar tidak bisa dibatalkan pembeli.
        $o2 = $this->lunas($this->checkout($user));

        $res = $this->kirim('POST', "/api/v1/orders/{$o2->uuid}/cancel", [], $token)->assertStatus(422);

        $this->assertStringContainsString('sudah dibayar', $res->json('errors.order.0'));
        $this->assertSame('paid', $o2->fresh()->status);
    }

    public function test_pembeli_mengonfirmasi_pesanan_diterima_hanya_setelah_sampai(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();

        $o   = $this->lunas($this->checkout($user));
        $url = "/api/v1/orders/{$o->uuid}/complete";

        // Belum sampai: ditolak.
        $this->kirim('POST', $url, [], $token)->assertStatus(422)->assertJsonValidationErrors(['order']);

        $o->transitionTo(Order::STATUS_PROCESSING, Order::ACTOR_SELLER);
        $o->transitionTo(Order::STATUS_PACKED, Order::ACTOR_SELLER);
        $o->transitionTo(Order::STATUS_SHIPPED, Order::ACTOR_SYSTEM);
        $o->transitionTo(Order::STATUS_DELIVERED, Order::ACTOR_SYSTEM);

        $this->assertNotNull($o->fresh()->auto_complete_at);

        $this->kirim('GET', "/api/v1/orders/{$o->uuid}", [], $token)->assertOk()->assertJsonPath('data.actions.complete', true);
        $this->kirim('POST', $url, [], $tokenLain)->assertStatus(404);

        $this->kirim('POST', $url, [], $token)
            ->assertOk()
            ->assertJson(['message' => 'Pesanan dikonfirmasi diterima.'])
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.actions.complete', false);

        $this->assertNotNull($o->fresh()->completed_at);

        $terakhir = $o->fresh()->statusHistories()->reorder()->orderByDesc('id')->first();

        $this->assertSame(['completed', 'buyer'], [$terakhir->to_status, $terakhir->actor_type]);

        // Dikonfirmasi dua kali ditolak.
        $this->kirim('POST', $url, [], $token)->assertStatus(422);
    }

    public function test_faktur_tersedia_setelah_dibayar_dan_hanya_untuk_pembelinya(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();

        $grup = $this->checkout($user);
        $o    = $this->pesanan($grup);
        $url  = "/api/v1/orders/{$o->uuid}/invoice";

        $this->kirim('GET', $url, [], $token)
            ->assertStatus(422)->assertJsonPath('errors.order.0', 'Faktur tersedia setelah pesanan dibayar.');

        $o = $this->lunas($grup);

        $res = $this->kirim('GET', $url, [], $token)
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.invoice_number', 'INV/' . $o->number)
            ->assertJsonPath('data.order_number', $o->number)
            ->assertJsonPath('data.checkout_number', $grup->number)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.buyer.name', $user->name)
            ->assertJsonPath('data.seller.name', $o->store->name)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', 100000)
            ->assertJsonPath('data.items.0.subtotal', 200000)
            ->assertJsonPath('data.totals', ['subtotal' => 200000, 'shipping' => 20000, 'discount' => 10000, 'order_total' => 210000])
            ->assertJsonPath('data.checkout.service_fee', 1000)
            ->assertJsonPath('data.checkout.grand_total', 211000)
            ->assertJsonPath('data.payment', null)
            ->assertJsonMissingPath('data.shipping_address.biteship_area_id');

        $this->assertStringNotContainsString($user->email, $res->getContent());

        $this->kirim('GET', $url, [], $tokenLain)->assertStatus(404);
    }
}
