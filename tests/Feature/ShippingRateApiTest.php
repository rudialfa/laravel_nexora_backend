<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\ShippingQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menguji cek ongkos kirim lewat HTTP (Biteship dipalsukan, tanpa memakai kuota).
 */
class ShippingRateApiTest extends TestCase
{
    use RefreshDatabase;

    private const UUID_ASING = '00000000-0000-4000-8000-000000000000';

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'biteship.key'              => 'test-key',
            'biteship.base_url'         => 'https://api.biteship.com',
            'biteship.default_couriers' => ['jne', 'sicepat', 'anteraja'],
        ]);
    }

    // ----------------------------------------------------------------- BANTUAN

    private function hitung(array $data, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->postJson('/api/v1/shipping/rates', $data);
    }

    /** @return array{0: User, 1: string} */
    private function pembeli(string $status = 'active'): array
    {
        $this->urut++;

        $user = User::create([
            'name'     => "Pembeli {$this->urut}",
            'email'    => "pembeli{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);

        if ($status !== 'active') {
            $user->forceFill(['status' => $status])->save();
        }

        return [$user, $user->createToken('test', ['*'], now()->addDay())->plainTextToken];
    }

    /** Opsi: area (string atau null), buka (bool), kurir (kode => aktif). */
    private function toko(array $o = []): Store
    {
        $this->urut++;

        $pemilik = User::create([
            'name'     => "Pemilik {$this->urut}",
            'email'    => "pemilik{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);

        $toko = new Store([
            'name'        => "Toko {$this->urut}",
            'slug'        => "toko-{$this->urut}",
            'phone'       => '628123456789',
            'province'    => 'DI Yogyakarta',
            'city'        => 'Sleman',
            'district'    => 'Depok',
            'postal_code' => '55281',
            'street'      => 'Jl. Rahasia No. 1',
        ]);
        $toko->owner_id = $pemilik->id;
        $toko->save();

        $toko->forceFill([
            'biteship_area_id' => array_key_exists('area', $o) ? $o['area'] : 'AREA-ASAL',
            'is_open'          => $o['buka'] ?? true,
        ])->save();

        foreach ($o['kurir'] ?? [] as $kode => $aktif) {
            $toko->courierSettings()->forceCreate(['courier_code' => $kode, 'is_active' => $aktif]);
        }

        return $toko->fresh();
    }

    private function alamat(User $user, ?string $area = 'AREA-TUJUAN'): Address
    {
        $alamat = new Address;
        $alamat->forceFill([
            'user_id'          => $user->id,
            'label'            => 'Rumah',
            'recipient_name'   => 'Budi',
            'phone'            => '628123456789',
            'province'         => 'DI Yogyakarta',
            'city'             => 'Sleman',
            'district'         => 'Depok',
            'postal_code'      => '55281',
            'street'           => 'Jl. Contoh No. 1',
            'biteship_area_id' => $area,
        ])->save();

        return $alamat->fresh();
    }

    private function kategori(): Category
    {
        $this->urut++;

        return Category::create(['name' => "Kategori {$this->urut}", 'slug' => "kategori-{$this->urut}"]);
    }

    /** Opsi: produk, nama, harga, diskon, berat, stok, dim (panjang, lebar, tinggi). */
    private function varian(Store $toko, array $o = []): ProductVariant
    {
        $this->urut++;

        $produk = new Product([
            'name'        => $o['produk'] ?? "Produk {$this->urut}",
            'slug'        => "produk-{$this->urut}",
            'category_id' => $this->kategori()->id,
        ]);
        $produk->store_id = $toko->id;
        $produk->forceFill(['status' => Product::STATUS_ACTIVE, 'published_at' => now()])->save();

        $varian = $produk->variants()->create([
            'name'           => $o['nama'] ?? 'Default',
            'price'          => $o['harga'] ?? 100000,
            'discount_price' => $o['diskon'] ?? null,
            'weight_gram'    => $o['berat'] ?? 200,
            'is_active'      => true,
        ]);

        if (isset($o['dim'])) {
            $varian->forceFill(['length_cm' => $o['dim'][0], 'width_cm' => $o['dim'][1], 'height_cm' => $o['dim'][2]])->save();
        }

        $stok = $o['stok'] ?? 10;

        if ($stok > 0) {
            $varian->adjustStock($stok, 'initial');
        }

        $produk->images()->create(['path' => "products/foto-{$this->urut}.jpg", 'sort_order' => 0]);

        return $varian->fresh();
    }

    private function isi(User $user, ProductVariant $varian, int $jumlah = 1, bool $dipilih = true): void
    {
        CartItem::create([
            'user_id'     => $user->id,
            'variant_id'  => $varian->id,
            'quantity'    => $jumlah,
            'is_selected' => $dipilih,
        ]);
    }

    /** Satu pembeli dengan satu alamat bertujuan dan satu item di toko. @return array{0: string, 1: Store, 2: Address, 3: User} */
    private function siap(array $opsiToko = []): array
    {
        $toko = $this->toko($opsiToko);
        [$user, $token] = $this->pembeli();
        $alamat = $this->alamat($user);
        $this->isi($user, $this->varian($toko));

        return [$token, $toko, $alamat, $user];
    }

    private function palsuTarif(): void
    {
        $dasar = ['description' => 'Layanan', 'duration' => '1 - 2 days', 'service_type' => 'standard', 'shipping_type' => 'parcel'];

        Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'pricing' => [
            $dasar + ['courier_code' => 'sicepat', 'courier_name' => 'SiCepat', 'courier_service_code' => 'reg', 'courier_service_name' => 'Reguler', 'price' => 20000],
            $dasar + ['courier_code' => 'jne', 'courier_name' => 'JNE', 'courier_service_code' => 'ctc', 'courier_service_name' => 'City to City (CTC)', 'price' => 9000],
        ]])]);
    }

    // -------------------------------------------------------------------- TES

    public function test_cek_ongkir_butuh_login_dan_akun_aktif(): void
    {
        Http::fake();
        $data = ['store_id' => self::UUID_ASING, 'address_id' => self::UUID_ASING];

        $this->hitung($data)->assertStatus(401);

        [, $token] = $this->pembeli('suspended');
        $this->hitung($data, $token)->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_cek_ongkir_menolak_masukan_tidak_valid(): void
    {
        Http::fake();
        [, $token] = $this->pembeli();

        $kasus = [
            [[], ['store_id', 'address_id']],
            [['store_id' => 'abc', 'address_id' => self::UUID_ASING], ['store_id']],
            [['store_id' => self::UUID_ASING, 'address_id' => 'abc'], ['address_id']],
            [['store_id' => self::UUID_ASING], ['address_id']],
            [['address_id' => self::UUID_ASING], ['store_id']],
        ];

        foreach ($kasus as [$data, $kolom]) {
            $this->hitung($data, $token)->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors($kolom);
        }

        Http::assertNothingSent();
    }

    public function test_cek_ongkir_hanya_memakai_item_terpilih_dan_kurir_aktif_toko_dan_mengurutkan_termurah(): void
    {
        $this->palsuTarif();

        $toko = $this->toko(['kurir' => ['jne' => true, 'sicepat' => true, 'anteraja' => false]]);
        $lain = $this->toko();
        [$user, $token] = $this->pembeli();
        $alamat = $this->alamat($user);

        $v1 = $this->varian($toko, ['produk' => 'Sepatu', 'nama' => 'Hitam / 42', 'harga' => 100000, 'diskon' => 80000, 'berat' => 300, 'dim' => [30, 20, 10]]);
        $v2 = $this->varian($toko);                    // tidak dipilih
        $v3 = $this->varian($toko, ['stok' => 0]);     // stok habis
        $v4 = $this->varian($lain);                    // toko lain

        $this->isi($user, $v1, 2);
        $this->isi($user, $v2, 1, false);
        $this->isi($user, $v3);
        $this->isi($user, $v4);

        $res = $this->hitung(['store_id' => $toko->uuid, 'address_id' => $alamat->uuid], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Pilihan ongkos kirim berhasil diambil.'])
            ->assertJsonPath('data.store.id', $toko->uuid)
            ->assertJsonPath('data.address.id', $alamat->uuid)
            ->assertJsonPath('data.summary', ['items' => 1, 'quantity' => 2, 'weight_gram' => 600, 'subtotal' => 160000]);

        // Termurah lebih dulu.
        $this->assertSame([9000, 20000], collect($res->json('data.options'))->pluck('price')->all());
        $this->assertSame(['jne', 'ctc', 'City to City (CTC)'], [
            $res->json('data.options.0.courier_code'),
            $res->json('data.options.0.service_code'),
            $res->json('data.options.0.service_name'),
        ]);
        $this->assertStringNotContainsString('test-key', $res->getContent());
        $this->assertStringNotContainsString('AREA-ASAL', $res->getContent());

        Http::assertSentCount(1);
        Http::assertSent(fn(Request $r) => $r->hasHeader('Authorization', 'test-key')
            && $r['origin_area_id'] === 'AREA-ASAL'
            && $r['destination_area_id'] === 'AREA-TUJUAN'
            && $r['couriers'] === 'jne,sicepat'
            && count($r['items']) === 1
            && $r['items'][0]['name'] === 'Sepatu - Hitam / 42'
            && $r['items'][0]['value'] === 80000
            && $r['items'][0]['quantity'] === 2
            && $r['items'][0]['weight'] === 300
            && $r['items'][0]['length'] === 30
            && $r['items'][0]['width'] === 20
            && $r['items'][0]['height'] === 10);
    }

    public function test_toko_tanpa_pengaturan_kurir_memakai_bawaan_dan_semua_nonaktif_ditolak(): void
    {
        $this->palsuTarif();

        [$user, $token] = $this->pembeli();
        $alamat = $this->alamat($user);

        $tokoBawaan = $this->toko();
        $tokoMati   = $this->toko(['kurir' => ['jne' => false, 'sicepat' => false]]);
        $this->isi($user, $this->varian($tokoBawaan));
        $this->isi($user, $this->varian($tokoMati));

        $this->hitung(['store_id' => $tokoBawaan->uuid, 'address_id' => $alamat->uuid], $token)->assertOk();

        Http::assertSent(fn(Request $r) => $r['couriers'] === 'anteraja,jne,sicepat');

        $this->hitung(['store_id' => $tokoMati->uuid, 'address_id' => $alamat->uuid], $token)
            ->assertStatus(422)
            ->assertJsonPath('errors.store_id.0', ShippingQuoteService::PESAN_TANPA_KURIR);

        Http::assertSentCount(1);
    }

    public function test_cek_ongkir_ditolak_untuk_toko_libur_area_kosong_dan_item_tidak_ada(): void
    {
        Http::fake();

        [$user, $token] = $this->pembeli();
        $alamat = $this->alamat($user);

        $libur      = $this->toko(['buka' => false]);
        $tanpaArea  = $this->toko(['area' => null]);
        $tanpaItem  = $this->toko();
        $takDipilih = $this->toko();

        $this->isi($user, $this->varian($libur));
        $this->isi($user, $this->varian($tanpaArea));
        $this->isi($user, $this->varian($takDipilih), 1, false);

        $kasus = [
            [$libur, ShippingQuoteService::PESAN_TOKO_LIBUR],
            [$tanpaArea, ShippingQuoteService::PESAN_TOKO_TANPA_AREA],
            [$tanpaItem, ShippingQuoteService::PESAN_TIDAK_ADA_ITEM],
            [$takDipilih, ShippingQuoteService::PESAN_TIDAK_ADA_ITEM],
        ];

        foreach ($kasus as [$toko, $pesan]) {
            $this->hitung(['store_id' => $toko->uuid, 'address_id' => $alamat->uuid], $token)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonPath('errors.store_id.0', $pesan);
        }

        // Toko yang tidak ada sama sekali dijawab sama dengan toko tanpa item.
        $this->hitung(['store_id' => self::UUID_ASING, 'address_id' => $alamat->uuid], $token)
            ->assertStatus(422)->assertJsonPath('errors.store_id.0', ShippingQuoteService::PESAN_TIDAK_ADA_ITEM);

        Http::assertNothingSent();
    }

    public function test_alamat_tanpa_area_ditolak_dan_alamat_orang_lain_atau_terhapus_dijawab_404(): void
    {
        Http::fake();

        [$token, $toko,, $user] = $this->siap();
        [,, $alamatLain]        = $this->siap();

        $tanpaArea = $this->alamat($user, null);
        $this->hitung(['store_id' => $toko->uuid, 'address_id' => $tanpaArea->uuid], $token)
            ->assertStatus(422)
            ->assertJsonPath('errors.address_id.0', ShippingQuoteService::PESAN_ALAMAT_TANPA_AREA);

        $this->hitung(['store_id' => $toko->uuid, 'address_id' => $alamatLain->uuid], $token)->assertStatus(404);
        $this->hitung(['store_id' => $toko->uuid, 'address_id' => self::UUID_ASING], $token)->assertStatus(404);

        $dihapus = $this->alamat($user);
        $dihapus->delete();
        $this->hitung(['store_id' => $toko->uuid, 'address_id' => $dihapus->uuid], $token)->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_tidak_ada_kurir_untuk_tujuan_menghasilkan_daftar_kosong(): void
    {
        Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['success' => false, 'code' => 40001010], 400)]);

        [$token, $toko, $alamat] = $this->siap();

        $this->hitung(['store_id' => $toko->uuid, 'address_id' => $alamat->uuid], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Tidak ada layanan pengiriman untuk tujuan ini.'])
            ->assertJsonPath('data.options', [])
            ->assertJsonPath('data.summary.quantity', 1);
    }

    public function test_kegagalan_biteship_dijawab_aman_tanpa_membocorkan_rincian(): void
    {
        Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['error' => 'rahasia internal'], 500)]);

        [$token, $toko, $alamat] = $this->siap();

        $res = $this->hitung(['store_id' => $toko->uuid, 'address_id' => $alamat->uuid], $token)
            ->assertStatus(503)
            ->assertJson(['success' => false, 'message' => 'Layanan pengiriman sedang tidak tersedia.']);

        $this->assertStringNotContainsString('rahasia internal', $res->getContent());
        $this->assertStringNotContainsString('test-key', $res->getContent());
    }

    public function test_cek_ongkir_dibatasi_dua_puluh_per_menit(): void
    {
        $this->palsuTarif();

        [$token, $toko, $alamat] = $this->siap();
        $data = ['store_id' => $toko->uuid, 'address_id' => $alamat->uuid];

        for ($n = 1; $n <= 20; $n++) {
            $this->hitung($data, $token)->assertOk();
        }

        $this->hitung($data, $token)->assertStatus(429);
    }
}
