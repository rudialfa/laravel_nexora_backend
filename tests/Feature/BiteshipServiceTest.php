<?php

namespace Tests\Feature;

use App\Exceptions\BiteshipException;
use App\Services\BiteshipService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Menguji penghubung Biteship dengan Http::fake (tanpa memanggil Biteship sungguhan). */
class BiteshipServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['biteship.key' => 'test-key', 'biteship.base_url' => 'https://api.biteship.com']);
    }

    private function item(): array
    {
        return ['name' => 'Sepatu', 'value' => 350000, 'length' => 32, 'width' => 20, 'height' => 12, 'weight' => 900, 'quantity' => 2];
    }

    public function test_cari_area_memetakan_hasil_dan_menyimpannya_sementara(): void
    {
        Http::fake(['api.biteship.com/v1/maps/areas*' => Http::response(['success' => true, 'areas' => [[
            'id' => 'IDNP6IDNC148IDND843IDZ12250',
            'name' => 'Pesanggrahan, Jakarta Selatan, DKI Jakarta. 12250',
            'administrative_division_level_1_name' => 'DKI Jakarta',
            'administrative_division_level_2_name' => 'Jakarta Selatan',
            'administrative_division_level_3_name' => 'Pesanggrahan',
            'postal_code' => 12250,
        ]]])]);

        $service = new BiteshipService;
        $hasil   = $service->searchAreas('  Jakarta   Selatan ');

        $this->assertSame([[
            'id' => 'IDNP6IDNC148IDND843IDZ12250',
            'name' => 'Pesanggrahan, Jakarta Selatan, DKI Jakarta. 12250',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Selatan',
            'district' => 'Pesanggrahan',
            'postal_code' => '12250',
        ]], $hasil);

        // Pencarian sama (beda huruf besar dan spasi) tidak memanggil Biteship lagi.
        $this->assertSame($hasil, $service->searchAreas('jakarta selatan'));

        Http::assertSentCount(1);
        Http::assertSent(fn(Request $r) => $r->hasHeader('Authorization', 'test-key')
            && str_contains($r->url(), 'countries=ID')
            && str_contains($r->url(), 'type=single')
            && str_contains(urldecode($r->url()), 'input=Jakarta Selatan'));
    }

    public function test_cari_area_dengan_teks_terlalu_pendek_tidak_memanggil_biteship(): void
    {
        Http::fake();

        $this->assertSame([], (new BiteshipService)->searchAreas('ab'));
        $this->assertSame([], (new BiteshipService)->searchAreas('   '));

        Http::assertNothingSent();
    }

    public function test_cek_ongkir_mengirim_area_id_dan_merapikan_hasil(): void
    {
        Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'pricing' => [[
            'courier_code' => 'jne',
            'courier_name' => 'JNE',
            'courier_service_code' => 'reg',
            'courier_service_name' => 'Reguler',
            'description' => 'Layanan reguler',
            'duration' => '2 - 3 days',
            'service_type' => 'standard',
            'shipping_type' => 'parcel',
            'shipping_fee' => 9000,
            'price' => 9000,
        ]]])]);

        $hasil = (new BiteshipService)->rates('AREA-ASAL', 'AREA-TUJUAN', [$this->item()], ['jne', 'sicepat']);

        $this->assertCount(1, $hasil);
        $this->assertSame('jne', $hasil[0]['courier_code']);
        $this->assertSame('reg', $hasil[0]['service_code']);
        $this->assertSame(9000, $hasil[0]['price']);

        Http::assertSent(fn(Request $r) => $r->hasHeader('Authorization', 'test-key')
            && $r['origin_area_id'] === 'AREA-ASAL'
            && $r['destination_area_id'] === 'AREA-TUJUAN'
            && $r['couriers'] === 'jne,sicepat'
            && $r['items'][0]['weight'] === 900
            && $r['items'][0]['quantity'] === 2);
    }

    public function test_tidak_ada_kurir_atau_masukan_kosong_menghasilkan_daftar_kosong(): void
    {
        Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['success' => false, 'code' => 40001010], 400)]);

        $this->assertSame([], (new BiteshipService)->rates('A', 'B', [$this->item()], ['jne']));

        Http::assertSentCount(1);

        // Tanpa kurir atau tanpa item: tidak perlu memanggil Biteship.
        $this->assertSame([], (new BiteshipService)->rates('A', 'B', [$this->item()], []));
        $this->assertSame([], (new BiteshipService)->rates('A', 'B', [], ['jne']));

        Http::assertSentCount(1);
    }

    public function test_kegagalan_biteship_dipetakan_ke_pesan_dan_status_aman(): void
    {
        Http::fake(['api.biteship.com/v1/rates/couriers' => Http::sequence()
            ->push(['success' => false, 'error' => 'unauthorized'], 401)
            ->push(['success' => false, 'code' => 42900000], 429)
            ->push(['success' => false, 'code' => 42200000], 422)
            ->push(['success' => false, 'code' => 40001001], 400)]);

        // $harapan = [['not_configured', 503], ['rate_limited', 429], ['quota', 503], ['invalid_area', 422]];
        $harapan = [['unavailable', 503], ['rate_limited', 429], ['quota', 503], ['invalid_area', 422]];

        foreach ($harapan as [$kode, $status]) {
            try {
                (new BiteshipService)->rates('A', 'B', [$this->item()], ['jne']);
                $this->fail("Seharusnya gagal dengan kode {$kode}");
            } catch (BiteshipException $e) {
                $this->assertSame($status, $e->httpStatus, $kode);
                $this->assertStringNotContainsString('test-key', $e->getMessage());
            }
        }
    }

    public function test_tanpa_api_key_langsung_gagal_tanpa_memanggil_biteship(): void
    {
        config(['biteship.key' => null]);
        Http::fake();

        try {
            (new BiteshipService)->searchAreas('Sleman');
            $this->fail('Seharusnya gagal karena key kosong');
        } catch (BiteshipException $e) {
            $this->assertSame('not_configured', $e->kode);
            $this->assertSame(503, $e->httpStatus);
        }

        Http::assertNothingSent();
    }
}
