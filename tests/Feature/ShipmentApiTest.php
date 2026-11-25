<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Refund;
use App\Models\Shipment;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SiapkanPengiriman;
use Tests\TestCase;

/**
 * Menguji permintaan kurir, pembatalan, dan pelacakan lewat HTTP. Biteship dipalsukan.
 */
class ShipmentApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanPengiriman;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturPengiriman();
    }

    public function test_endpoint_pengiriman_butuh_login_akun_aktif_dan_peran_yang_sesuai(): void
    {
        Http::fake();

        $u     = $this->uuidAsing();
        $rute  = [['POST', "/api/v1/seller/orders/{$u}/ship"], ['GET', "/api/v1/seller/orders/{$u}/tracking"], ['GET', "/api/v1/orders/{$u}/tracking"]];

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif] = $this->pembeli('suspended');
        [, $biasa]    = $this->pembeli();

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
        }

        foreach (array_slice($rute, 0, 2) as [$method, $uri]) {
            $this->kirim($method, $uri, [], $biasa)->assertStatus(403);
        }

        Http::assertNothingSent();
    }

    public function test_permintaan_kurir_menolak_masukan_tidak_valid(): void
    {
        Http::fake();

        [$user] = $this->pembeli();
        $o      = $this->dikemas($user);
        $token  = $this->penjual($o);

        $kasus = [
            [['delivery_type' => 'later'], 'delivery_type'],
            [['delivery_type' => 'scheduled'], 'delivery_date'],
            [['delivery_type' => 'scheduled', 'delivery_date' => now()->subDay()->toDateString(), 'delivery_time' => '10:00'], 'delivery_date'],
            [['delivery_type' => 'scheduled', 'delivery_date' => now()->addDays(30)->toDateString(), 'delivery_time' => '10:00'], 'delivery_date'],
            [['delivery_type' => 'scheduled', 'delivery_date' => now()->addDay()->toDateString(), 'delivery_time' => '25:99'], 'delivery_time'],
            [['note' => str_repeat('a', 201)], 'note'],
        ];

        foreach ($kasus as [$data, $kolom]) {
            $this->kirim('POST', $this->urlKirim($o), $data, $token)
                ->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame(0, Shipment::count());
        Http::assertNothingSent();
    }

    public function test_seller_meminta_kurir_dan_isi_permintaan_ke_biteship_benar(): void
    {
        [$user] = $this->pembeli();
        $o      = $this->dikemas($user);
        $token  = $this->penjual($o);
        $this->palsuBiteship();

        $this->kirim('POST', $this->urlKirim($o), ['note' => 'Hati-hati, barang pecah belah'], $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Pengiriman dibuat. Kurir akan menjemput sesuai jadwal.'])
            ->assertJsonPath('data.status', 'packed')
            ->assertJsonPath('data.shipment.status', 'confirmed')
            ->assertJsonPath('data.shipment.courier_company', 'jne')
            ->assertJsonPath('data.shipment.courier_type', 'reg')
            ->assertJsonPath('data.shipment.waybill_id', null);

        $s = Shipment::first();

        $this->assertSame($o->id, (int) $s->order_id);
        $this->assertStringStartsWith('BTS-', $s->biteship_order_id);
        $this->assertSame('confirmed', $s->status);
        $this->assertSame(24000, (int) $s->courier_price);
        $this->assertSame(['confirmed'], $s->trackingHistories()->pluck('status')->all());
        $this->assertSame('packed', $o->fresh()->status);   // tetap dikemas sampai kurir menjemput

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) use ($o) {
            return str_ends_with($r->url(), '/v1/orders')
                && $r->hasHeader('Authorization', 'test-key')
                && $r['reference_id'] === $o->number
                && $r['courier_company'] === 'jne'
                && $r['courier_type'] === 'reg'
                && $r['delivery_type'] === 'now'
                && $r['origin_contact_name'] === $o->store->name
                && $r['origin_contact_phone'] === '628123456789'
                && $r['origin_postal_code'] === 55281
                && str_contains($r['origin_address'], 'Jl. Rahasia No. 1')
                && $r['destination_contact_name'] === 'Budi Penerima'
                && $r['destination_contact_phone'] === '628123456780'
                && $r['destination_postal_code'] === 12430
                && str_contains($r['destination_address'], 'Jl. Contoh Pembeli No. 2')
                && $r['order_note'] === 'Hati-hati, barang pecah belah'
                && $r['items'][0]['quantity'] === 2
                && $r['items'][0]['value'] === 100000
                && $r['items'][0]['weight'] === 200
                && $r['metadata']['order_number'] === $o->number
                && ! isset($r['origin_coordinate']);
        });

        // Permintaan kedua untuk pesanan yang sama ditolak dan tidak memanggil Biteship lagi.
        $this->kirim('POST', $this->urlKirim($o), [], $token)
            ->assertStatus(422)->assertJsonPath('errors.order.0', ShipmentService::PESAN_SUDAH_DIBUAT);

        $this->assertSame(1, Shipment::count());
        Http::assertSentCount(1);
    }

    public function test_permintaan_kurir_ditolak_untuk_pesanan_yang_belum_dikemas_milik_toko_lain_atau_belum_dibayar(): void
    {
        Http::fake();

        [$user] = $this->pembeli();

        $dikemas = $this->dikemas($user);
        $tokenA  = $this->penjual($dikemas);

        $baruLunas = $this->lunas($this->checkout($user));
        $tokenB    = $this->penjual($baruLunas);

        $grupBelum  = $this->checkout($user);
        $belumBayar = Order::where('checkout_group_id', $grupBelum->id)->first();
        $tokenC     = $this->penjual($belumBayar);

        // Sudah dibayar tetapi belum dikemas.
        $this->kirim('POST', $this->urlKirim($baruLunas), [], $tokenB)
            ->assertStatus(422)->assertJsonPath('errors.order.0', ShipmentService::PESAN_BUKAN_DIKEMAS);

        // Milik toko lain, belum dibayar, dan tidak ada: 404.
        $this->kirim('POST', $this->urlKirim($dikemas), [], $tokenB)->assertStatus(404);
        $this->kirim('POST', $this->urlKirim($belumBayar), [], $tokenC)->assertStatus(404);
        $this->kirim('POST', "/api/v1/seller/orders/{$this->uuidAsing()}/ship", [], $tokenA)->assertStatus(404);

        $this->assertSame(0, Shipment::count());
        Http::assertNothingSent();
    }

    public function test_kegagalan_biteship_dijawab_aman_dan_tidak_meninggalkan_pengiriman(): void
    {
        [$user] = $this->pembeli();
        $o      = $this->dikemas($user);
        $token  = $this->penjual($o);

        Http::fake(['api.biteship.com/v1/orders' => Http::sequence()
            ->push(['error' => 'rahasia internal'], 500)
            ->push(['success' => false, 'code' => 40000001, 'error' => 'rahasia data'], 400)]);

        $a = $this->kirim('POST', $this->urlKirim($o), [], $token)
            ->assertStatus(503)->assertJson(['success' => false, 'message' => 'Layanan pengiriman sedang tidak tersedia.']);

        $b = $this->kirim('POST', $this->urlKirim($o), [], $token)->assertStatus(422)->assertJson(['success' => false]);

        $this->assertStringContainsString('ditolak', $b->json('message'));

        foreach ([$a, $b] as $res) {
            $this->assertStringNotContainsString('rahasia', $res->getContent());
            $this->assertStringNotContainsString('test-key', $res->getContent());
        }

        // Key belum diisi: gagal tanpa memanggil Biteship.
        config(['biteship.key' => null]);

        $this->kirim('POST', $this->urlKirim($o), [], $token)->assertStatus(503);

        $this->assertSame(0, Shipment::count());
        $this->assertSame('packed', $o->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_pengiriman_yang_ditolak_kurir_boleh_diminta_ulang_pada_baris_yang_sama(): void
    {
        [$user] = $this->pembeli();
        $o      = $this->dikemas($user);
        $token  = $this->penjual($o);
        $this->palsuBiteship();

        $this->kirim('POST', $this->urlKirim($o), [], $token)->assertStatus(201);

        $s        = Shipment::first();
        $idLama   = $s->biteship_order_id;
        $baris    = $s->id;

        $s->applyStatus('rejected');

        $this->kirim('POST', $this->urlKirim($o), [], $token)
            ->assertStatus(201)->assertJsonPath('data.shipment.status', 'confirmed');

        $s = Shipment::first();

        $this->assertSame(1, Shipment::count());
        $this->assertSame($baris, $s->id);
        $this->assertNotSame($idLama, $s->biteship_order_id);
        $this->assertSame(['confirmed', 'rejected', 'confirmed'], $s->trackingHistories()->pluck('status')->all());

        // Nomor referensi untuk percobaan ulang tetap unik.
        $referensi = collect(Http::recorded())->map(fn($pasangan) => $pasangan[0]['reference_id'])->all();

        $this->assertSame($o->number, $referensi[0]);
        $this->assertStringStartsWith($o->number . '-', $referensi[1]);
        $this->assertNotSame($referensi[0], $referensi[1]);
    }

    public function test_seller_membatalkan_pesanan_dikemas_dan_order_biteship_ikut_dibatalkan(): void
    {
        [$user] = $this->pembeli();
        $o      = $this->dikemas($user);
        $token  = $this->penjual($o);
        $this->palsuBiteship();

        $this->kirim('POST', $this->urlKirim($o), [], $token)->assertStatus(201);

        $idBiteship = Shipment::first()->biteship_order_id;

        $this->kirim('POST', "/api/v1/seller/orders/{$o->uuid}/cancel", ['reason' => 'Barang rusak saat dikemas'], $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.shipment.status', 'cancelled');

        Http::assertSent(fn(Request $r) => str_ends_with($r->url(), "/v1/orders/{$idBiteship}/cancel")
            && $r['cancellation_reason_code'] === 'others'
            && str_contains($r['cancellation_reason'], 'Barang rusak'));

        $this->assertSame('cancelled', Shipment::first()->status);
        $this->assertSame(['confirmed', 'cancelled'], Shipment::first()->trackingHistories()->pluck('status')->all());
        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertSame(1, Refund::count());
    }

    public function test_pembatalan_pesanan_tetap_berhasil_walau_pembatalan_di_biteship_gagal(): void
    {
        [$user] = $this->pembeli();
        $o      = $this->dikemas($user);
        $token  = $this->penjual($o);

        Http::fake([
            'api.biteship.com/v1/orders'          => Http::response(['success' => true, 'id' => 'BTS-gagal-batal', 'status' => 'confirmed', 'price' => 24000, 'courier' => ['tracking_id' => 'TRK-X']], 200),
            'api.biteship.com/v1/orders/*/cancel' => Http::response(['error' => 'gagal'], 500),
        ]);

        $this->kirim('POST', $this->urlKirim($o), [], $token)->assertStatus(201);

        $this->kirim('POST', "/api/v1/seller/orders/{$o->uuid}/cancel", ['reason' => 'Barang rusak saat dikemas'], $token)
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Pesanan batal dan dana dicatat, sedangkan status pengiriman tidak berubah karena Biteship gagal.
        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertSame(1, Refund::count());
        $this->assertSame('confirmed', Shipment::first()->status);
    }

    public function test_pembeli_dan_seller_melihat_pelacakan_dengan_data_yang_sesuai_perannya(): void
    {
        [$user, $tokenPembeli] = $this->pembeli();
        [, $tokenLain]         = $this->pembeli();

        $o            = $this->dikemas($user);
        $tokenSeller  = $this->penjual($o);
        $tokenSellerB = $this->penjual($this->dikemas($user));
        $this->palsuBiteship();

        // Belum ada pengiriman.
        $this->kirim('GET', "/api/v1/orders/{$o->uuid}/tracking", [], $tokenPembeli)
            ->assertOk()->assertJsonPath('data.order.status', 'packed')->assertJsonPath('data.shipment', null);

        $this->kirim('POST', $this->urlKirim($o), [], $tokenSeller)->assertStatus(201);

        Shipment::first()->forceFill([
            'waybill_id' => 'WB-123',
            'driver'     => ['name' => 'Pak Kurir', 'phone' => '088888888888', 'plate_number' => 'B 1234 AAA', 'link' => 'https://track.example.com/abc'],
        ])->save();

        $pembeli = $this->kirim('GET', "/api/v1/orders/{$o->uuid}/tracking", [], $tokenPembeli)
            ->assertOk()
            ->assertJsonPath('data.shipment.status', 'confirmed')
            ->assertJsonPath('data.shipment.waybill_id', 'WB-123')
            ->assertJsonPath('data.shipment.driver.name', 'Pak Kurir')
            ->assertJsonPath('data.shipment.driver.plate_number', 'B 1234 AAA')
            ->assertJsonPath('data.shipment.history.0.status', 'confirmed')
            ->assertJsonMissingPath('data.shipment.driver.phone')
            ->assertJsonMissingPath('data.shipping_difference');

        $this->assertStringNotContainsString('088888888888', $pembeli->getContent());
        $this->assertStringNotContainsString('24000', $pembeli->getContent());

        $this->kirim('GET', "/api/v1/seller/orders/{$o->uuid}/tracking", [], $tokenSeller)
            ->assertOk()
            ->assertJsonPath('data.shipment.driver.phone', '088888888888')
            ->assertJsonPath('data.shipping_difference.buyer_paid', 20000)
            ->assertJsonPath('data.shipping_difference.carrier_price', 24000)
            ->assertJsonPath('data.shipping_difference.difference', 4000)
            ->assertJsonPath('data.shipping_difference.platform_absorbs', 4000)
            ->assertJsonPath('data.shipping_difference.seller_charge', 0);

        // Pembeli lain, seller toko lain, dan pesanan tidak ada: 404.
        $this->kirim('GET', "/api/v1/orders/{$o->uuid}/tracking", [], $tokenLain)->assertStatus(404);
        $this->kirim('GET', "/api/v1/seller/orders/{$o->uuid}/tracking", [], $tokenSellerB)->assertStatus(404);
        $this->kirim('GET', "/api/v1/orders/{$this->uuidAsing()}/tracking", [], $tokenPembeli)->assertStatus(404);
    }
}
