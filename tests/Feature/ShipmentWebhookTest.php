<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\WebhookLog;
use Database\Seeders\ShippingSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SiapkanPengiriman;
use Tests\TestCase;

/**
 * Menguji webhook Biteship, pemajuan status pesanan, selisih ongkir, dan perintah simulasi. Biteship dipalsukan.
 */
class ShipmentWebhookTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanPengiriman;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturPengiriman();
    }

    /** @return array{0: \App\Models\User, 1: string, 2: string, 3: Order, 4: Shipment} pembeli, token pembeli, token seller, pesanan, pengiriman */
    private function siapKirim(): array
    {
        [$user, $tokenPembeli] = $this->pembeli();

        $o           = $this->dikemas($user);
        $tokenSeller = $this->penjual($o);
        $this->palsuBiteship();

        $this->kirim('POST', $this->urlKirim($o), [], $tokenSeller)->assertStatus(201);

        return [$user, $tokenPembeli, $tokenSeller, $o, Shipment::first()];
    }

    private function riwayatPesanan(Order $o): array
    {
        return $o->statusHistories()->pluck('to_status')->all();
    }

    public function test_webhook_tanpa_token_yang_benar_ditolak_dan_ketiga_cara_mengirim_token_diterima(): void
    {
        [,,, $o, $s] = $this->siapKirim();

        $p = $this->statusPengiriman($s->biteship_order_id, 'picked');

        $this->webhook($p, null)->assertStatus(401)->assertJson(['success' => false]);
        $this->webhook($p, 'salah')->assertStatus(401);

        config(['biteship.webhook_token' => null]);
        $this->webhook($p, 'token-rahasia-uji')->assertStatus(401);
        config(['biteship.webhook_token' => 'token-rahasia-uji']);

        // Tidak ada yang berubah selama ditolak, dan semuanya tercatat.
        $this->assertSame('packed', $o->fresh()->status);
        $this->assertSame('confirmed', $s->fresh()->status);
        $this->assertSame(3, WebhookLog::where('processing_status', 'failed')->where('provider', 'biteship')->count());
        $this->assertFalse((bool) WebhookLog::orderBy('id')->first()->is_valid);

        // Header Authorization (Bearer) dan parameter ?token= juga diterima.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer token-rahasia-uji')
            ->postJson('/api/v1/webhooks/biteship', $p)->assertOk()->assertJson(['success' => true]);

        $this->assertSame('shipped', $o->fresh()->status);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/v1/webhooks/biteship?token=token-rahasia-uji', $this->statusPengiriman($s->biteship_order_id, 'in_transit'))
            ->assertOk();

        $this->assertSame('in_transit', $o->fresh()->status);
        $this->assertSame('in_transit', $s->fresh()->status);
    }

    public function test_kurir_menjemput_memajukan_pesanan_dan_webhook_ulang_tidak_menggandakan(): void
    {
        [,,, $o, $s] = $this->siapKirim();

        $p = $this->statusPengiriman($s->biteship_order_id, 'picked');

        $this->webhook($p)->assertOk()->assertJson(['success' => true]);

        $s = $s->fresh();

        $this->assertSame('picked', $s->status);
        $this->assertNotNull($s->picked_at);
        $this->assertSame('WB-123', $s->waybill_id);
        $this->assertSame('TRK-1', $s->tracking_id);
        $this->assertSame(24000, (int) $s->courier_price);
        $this->assertSame('Pak Kurir', $s->driver['name']);
        $this->assertSame(['confirmed', 'picked'], $s->trackingHistories()->pluck('status')->all());

        $this->assertSame('shipped', $o->fresh()->status);
        $this->assertNotNull($o->fresh()->shipped_at);
        $this->assertSame(['paid', 'processing', 'packed', 'shipped'], $this->riwayatPesanan($o));
        $this->assertSame('system', $o->statusHistories()->reorder()->orderByDesc('id')->first()->actor_type);

        // Dikirim ulang: tidak ada riwayat baru.
        $this->webhook($p)->assertOk()->assertJson(['message' => 'Status pengiriman sudah tercatat.']);

        $this->assertSame(['confirmed', 'picked'], $s->trackingHistories()->pluck('status')->all());
        $this->assertSame(4, $o->statusHistories()->count());
        $this->assertSame(['processed', 'ignored'], WebhookLog::orderBy('id')->pluck('processing_status')->all());
    }

    public function test_status_dalam_perjalanan_mengejar_langkah_yang_terlewat(): void
    {
        [,,, $o, $s] = $this->siapKirim();

        // Webhook "picked" tidak pernah datang: pesanan tetap dimajukan bertahap.
        $this->webhook($this->statusPengiriman($s->biteship_order_id, 'inTransit'))->assertOk();

        $this->assertSame('in_transit', $o->fresh()->status);
        $this->assertSame(['paid', 'processing', 'packed', 'shipped', 'in_transit'], $this->riwayatPesanan($o));
        $this->assertSame('in_transit', $s->fresh()->status);

        // "Mengantar ke pelanggan" dicatat di pengiriman, sedangkan pesanan tetap dalam perjalanan.
        $this->webhook($this->statusPengiriman($s->biteship_order_id, 'droppingOff'))->assertOk();

        $this->assertSame('dropping_off', $s->fresh()->status);
        $this->assertSame('in_transit', $o->fresh()->status);
        $this->assertSame(5, $o->statusHistories()->count());
    }

    public function test_paket_sampai_memajukan_pesanan_ke_diterima_dan_pembeli_bisa_mengonfirmasi(): void
    {
        [, $tokenPembeli,, $o, $s] = $this->siapKirim();

        $this->webhook($this->statusPengiriman($s->biteship_order_id, 'delivered'))->assertOk();

        $o = $o->fresh();
        $s = $s->fresh();

        $this->assertSame('delivered', $o->status);
        $this->assertNotNull($o->delivered_at);
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, Carbon::parse($o->auto_complete_at)->timestamp, 120);
        $this->assertSame(['paid', 'processing', 'packed', 'shipped', 'delivered'], $this->riwayatPesanan($o));
        $this->assertSame('delivered', $s->status);
        $this->assertNotNull($s->delivered_at);

        $this->kirim('POST', "/api/v1/orders/{$o->uuid}/complete", [], $tokenPembeli)
            ->assertOk()->assertJsonPath('data.status', 'completed');

        // Webhook terlambat setelah pesanan selesai tidak mengubah apa pun dan tidak dianggap gagal.
        $this->webhook($this->statusPengiriman($s->biteship_order_id, 'returned'))->assertOk();

        $this->assertSame('completed', $o->fresh()->status);
        $this->assertSame(0, WebhookLog::where('processing_status', 'failed')->count());
    }

    public function test_webhook_resi_dan_harga_memperbarui_data_dan_selisih_ongkir_dihitung(): void
    {
        [,, $tokenSeller, $o, $s] = $this->siapKirim();

        $this->seed(ShippingSettingSeeder::class);

        $this->webhook([
            'event' => 'order.waybill_id',
            'order_id' => $s->biteship_order_id,
            'courier_tracking_id' => 'TRK-2',
            'courier_waybill_id' => 'WB-999',
            'status' => 'picked',
        ])->assertOk();

        $this->webhook(['event' => 'order.price', 'order_id' => $s->biteship_order_id, 'price' => 30000, 'status' => 'picked'])->assertOk();

        $s = $s->fresh();

        $this->assertSame('WB-999', $s->waybill_id);
        $this->assertSame('TRK-2', $s->tracking_id);
        $this->assertSame(30000, (int) $s->courier_price);

        // Kedua event ini tidak mengubah status pengiriman maupun pesanan.
        $this->assertSame('confirmed', $s->status);
        $this->assertSame(['confirmed'], $s->trackingHistories()->pluck('status')->all());
        $this->assertSame('packed', $o->fresh()->status);

        $selisih = fn() => $this->kirim('GET', "/api/v1/seller/orders/{$o->uuid}/tracking", [], $tokenSeller)->assertOk()->json('data.shipping_difference');

        // Ongkir pembeli 20.000, aktual 30.000: selisih 10.000, platform menanggung 5.000 dan seller kelebihannya.
        $this->assertSame(
            ['buyer_paid' => 20000, 'carrier_price' => 30000, 'difference' => 10000, 'platform_absorbs' => 5000, 'seller_charge' => 5000],
            $selisih()
        );

        // Batas bisa diubah lewat pengaturan.
        \App\Models\Setting::put('shipping.platform_absorb_limit', 2000);
        $hasil = $selisih();

        $this->assertSame([2000, 8000], [$hasil['platform_absorbs'], $hasil['seller_charge']]);

        // Aktual lebih murah dari yang dibayar pembeli: tidak ada tanggungan.
        $this->webhook(['event' => 'order.price', 'order_id' => $s->biteship_order_id, 'price' => 18000, 'status' => 'picked'])->assertOk();

        $this->assertSame(
            ['buyer_paid' => 20000, 'carrier_price' => 18000, 'difference' => -2000, 'platform_absorbs' => 0, 'seller_charge' => 0],
            $selisih()
        );
    }

    public function test_webhook_tidak_lengkap_tidak_dikenal_atau_status_lain_ditangani_dengan_aman(): void
    {
        [,,, $o, $s] = $this->siapKirim();

        $this->webhook([])->assertStatus(400)->assertJson(['success' => false]);
        $this->webhook(['event' => 'order.status', 'order_id' => $s->biteship_order_id])->assertStatus(400);
        $this->webhook(['event' => 'order.foo', 'order_id' => $s->biteship_order_id])->assertOk()->assertJson(['message' => 'Event tidak dikenal.']);
        $this->webhook($this->statusPengiriman('TIDAK-ADA', 'picked'))->assertOk()->assertJson(['message' => 'Pengiriman tidak dikenal.']);

        // Status yang tidak menggerakkan pesanan hanya dicatat.
        $this->webhook($this->statusPengiriman($s->biteship_order_id, 'onHold'))->assertOk()->assertJson(['success' => true]);

        $this->assertSame('on_hold', $s->fresh()->status);
        $this->assertSame('packed', $o->fresh()->status);

        $this->assertSame(
            ['failed', 'failed', 'ignored', 'ignored', 'processed'],
            WebhookLog::orderBy('id')->pluck('processing_status')->all()
        );
    }

    public function test_webhook_untuk_pesanan_yang_sudah_dibatalkan_ditandai_untuk_ditinjau(): void
    {
        [,,, $o, $s] = $this->siapKirim();

        // Pesanan dibatalkan, tetapi kurir tetap menjemput (misalnya pembatalan di Biteship gagal).
        $o->transitionTo(Order::STATUS_CANCELLED, Order::ACTOR_SELLER);

        $this->webhook($this->statusPengiriman($s->biteship_order_id, 'picked'))->assertOk();

        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertSame('picked', $s->fresh()->status);

        $log = WebhookLog::orderByDesc('id')->first();

        $this->assertSame('failed', $log->processing_status);
        $this->assertStringContainsString('ditinjau', $log->error);
    }

    public function test_perintah_simulasi_memajukan_pesanan_dan_menolak_berjalan_di_production(): void
    {
        [,,, $o, $s] = $this->siapKirim();

        $this->artisan('nexora:simulate-shipment', ['biteship_order_id' => $s->biteship_order_id, 'status' => 'foo'])->assertExitCode(1);
        $this->artisan('nexora:simulate-shipment', ['biteship_order_id' => 'TIDAK-ADA', 'status' => 'picked'])->assertExitCode(1);

        $this->app['env'] = 'production';

        try {
            $this->artisan('nexora:simulate-shipment', ['biteship_order_id' => $s->biteship_order_id, 'status' => 'picked'])->assertExitCode(1);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame('packed', $o->fresh()->status);

        $this->artisan('nexora:simulate-shipment', ['biteship_order_id' => $s->biteship_order_id, 'status' => 'picked'])->assertExitCode(0);

        $this->assertSame('shipped', $o->fresh()->status);
        $this->assertSame('picked', $s->fresh()->status);
    }
}
