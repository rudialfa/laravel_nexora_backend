<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Menguji penelusuran katalog publik (daftar, pencarian, filter, pengurutan, beranda,
 * produk terkait, halaman toko). Semua tanpa login, di SQLite memori dan disk palsu.
 */
class CatalogBrowseApiTest extends TestCase
{
    use RefreshDatabase;

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // ----------------------------------------------------------------- BANTUAN

    private function ambil(string $uri)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->getJson($uri);
    }

    /** @return array<int, string> nama produk menurut urutan respons */
    private function nama($res): array
    {
        return collect($res->json('data'))->pluck('name')->all();
    }

    private function toko(string $status = 'active', array $isi = []): Store
    {
        $this->urut++;

        $pemilik = User::create([
            'name'     => "Pemilik {$this->urut}",
            'email'    => "pemilik{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);

        $toko = new Store(array_merge([
            'name'        => "Toko {$this->urut}",
            'slug'        => "toko-{$this->urut}",
            'phone'       => '628123456789',
            'province'    => 'DI Yogyakarta',
            'city'        => 'Sleman',
            'district'    => 'Depok',
            'postal_code' => '55281',
            'street'      => 'Jl. Rahasia No. 1',
        ], $isi));
        $toko->owner_id = $pemilik->id;
        $toko->save();

        if ($status !== 'active') {
            $toko->forceFill(['status' => $status])->save();
        }

        return $toko->fresh();
    }

    private function kategori(array $isi = []): Category
    {
        $this->urut++;

        return Category::create(array_merge([
            'name' => "Kategori {$this->urut}",
            'slug' => "kategori-{$this->urut}",
        ], $isi));
    }

    private function merek(array $isi = []): Brand
    {
        $this->urut++;

        return Brand::create(array_merge([
            'name' => "Brand {$this->urut}",
            'slug' => "brand-{$this->urut}",
        ], $isi));
    }

    /**
     * Membuat produk langsung di database. Opsi: brand, status, featured, sold, rating,
     * rating_count, published_at, harga, stok, varian_aktif, foto, dihapus.
     */
    private function produk(Store $toko, Category $kategori, string $nama, array $o = []): Product
    {
        $this->urut++;

        $produk = new Product([
            'name'        => $nama,
            'slug'        => Str::slug($nama) . '-' . $this->urut,
            'category_id' => $kategori->id,
            'brand_id'    => isset($o['brand']) ? $o['brand']->id : null,
        ]);
        $produk->store_id = $toko->id;
        $produk->forceFill([
            'status'       => $o['status'] ?? Product::STATUS_ACTIVE,
            'is_featured'  => $o['featured'] ?? false,
            'sold_count'   => $o['sold'] ?? 0,
            'rating_avg'   => $o['rating'] ?? 0,
            'rating_count' => $o['rating_count'] ?? 0,
            // Produk yang dibuat belakangan dianggap tayang lebih baru.
            'published_at' => $o['published_at'] ?? Carbon::parse('2026-01-01 00:00:00')->addMinutes($this->urut),
        ])->save();

        $varian = $produk->variants()->create([
            'name'        => 'Default',
            'price'       => $o['harga'] ?? 100000,
            'weight_gram' => 200,
            'is_active'   => $o['varian_aktif'] ?? true,
        ]);

        $stok = $o['stok'] ?? 5;

        if ($stok > 0) {
            $varian->adjustStock($stok, 'initial');
        }

        $produk->refreshPriceRange();

        if ($o['foto'] ?? true) {
            $produk->images()->create(['path' => "products/foto-{$this->urut}.jpg", 'sort_order' => 0]);
        }

        if ($o['dihapus'] ?? false) {
            $produk->delete();
        }

        return $produk;
    }

    // ------------------------------------------------------------------ DAFTAR

    public function test_daftar_produk_hanya_yang_layak_tayang_tanpa_login_dan_tanpa_data_internal(): void
    {
        $toko         = $this->toko();
        $tokoBlokir   = $this->toko('suspended');
        $kategori     = $this->kategori(['name' => 'Fashion', 'slug' => 'fashion']);
        $kategoriMati = $this->kategori(['name' => 'Arsip', 'slug' => 'arsip', 'is_active' => false]);
        $brandMati    = $this->merek(['is_active' => false]);

        $this->produk($toko, $kategori, 'Produk A');
        $this->produk($toko, $kategori, 'Produk B', ['brand' => $brandMati, 'harga' => 150000]);   // brand nonaktif: tetap tampil
        $this->produk($toko, $kategori, 'Draf', ['status' => 'draft']);
        $this->produk($toko, $kategori, 'Nonaktif', ['status' => 'inactive']);
        $this->produk($toko, $kategori, 'Ditangguhkan', ['status' => 'suspended']);
        $this->produk($toko, $kategori, 'Dihapus', ['dihapus' => true]);
        $this->produk($tokoBlokir, $kategori, 'Toko Diblokir');
        $this->produk($toko, $kategoriMati, 'Kategori Mati');

        $res = $this->ambil('/api/v1/products')->assertOk()->assertJson(['success' => true]);

        // Terbaru dulu.
        $this->assertSame(['Produk B', 'Produk A'], $this->nama($res));
        $this->assertSame(
            ['current_page' => 1, 'per_page' => 20, 'total' => 2, 'last_page' => 1],
            $res->json('meta')
        );

        $res->assertJsonStructure(['data' => ['*' => [
            'id',
            'name',
            'slug',
            'image_url',
            'price_min',
            'price_max',
            'rating_avg',
            'rating_count',
            'sold_count',
            'category' => ['name', 'slug'],
            'store'    => ['name', 'slug', 'city'],
        ]]]);

        $this->assertSame(36, strlen($res->json('data.0.id')));
        $this->assertFalse(is_numeric($res->json('data.0.id')));
        $this->assertStringContainsString('products/foto-', $res->json('data.0.image_url'));
        $this->assertSame([150000, 150000], [$res->json('data.0.price_min'), $res->json('data.0.price_max')]);
        $this->assertSame(['fashion', 'Sleman'], [$res->json('data.0.category.slug'), $res->json('data.0.store.city')]);

        foreach (['status', 'variants', 'is_featured', 'store_id', 'deleted_at', 'store.phone', 'store.street', 'store.owner_id'] as $jalur) {
            $res->assertJsonMissingPath("data.0.{$jalur}");
        }
        $this->assertStringNotContainsString('Jl. Rahasia', $res->getContent());
        $this->assertStringNotContainsString('628123456789', $res->getContent());
    }

    // --------------------------------------------------------------- PENCARIAN

    public function test_pencarian_semua_kata_harus_cocok_di_nama_atau_brand_aktif_dan_aman(): void
    {
        $toko      = $this->toko();
        $kategori  = $this->kategori();
        $nexora    = $this->merek(['name' => 'Nexora Wear', 'slug' => 'nexora-wear']);
        $merekMati = $this->merek(['name' => 'Merek Mati', 'slug' => 'merek-mati', 'is_active' => false]);

        $this->produk($toko, $kategori, 'Kaos Polos Merah');
        $this->produk($toko, $kategori, 'Kaos Biru');
        $this->produk($toko, $kategori, 'Celana Polos');
        $this->produk($toko, $kategori, 'Jaket Musim Dingin', ['brand' => $nexora]);
        $this->produk($toko, $kategori, 'Sepatu Lari', ['brand' => $merekMati]);
        $this->produk($toko, $kategori, '100% Katun');

        $cari = fn(string $q) => $this->nama($this->ambil('/api/v1/products?q=' . urlencode($q))->assertOk());

        // Semua kata harus cocok, urutan bebas, spasi berlebih diabaikan.
        $this->assertSame(['Kaos Polos Merah'], $cari('kaos polos'));
        $this->assertSame(['Kaos Polos Merah'], $cari('polos kaos'));
        $this->assertSame(['Kaos Polos Merah'], $cari('  kaos    polos  '));
        $this->assertSame(['Celana Polos', 'Kaos Polos Merah'], $cari('polos'));
        $this->assertSame(['Kaos Biru', 'Kaos Polos Merah'], $cari('KAOS'));

        // Brand aktif ikut dicari; kombinasi dengan nama; brand nonaktif tidak.
        $this->assertSame(['Jaket Musim Dingin'], $cari('nexora'));
        $this->assertSame(['Jaket Musim Dingin'], $cari('NEXORA jaket'));
        $this->assertSame([], $cari('nexora kaos'));
        $this->assertSame([], $cari('merek mati'));
        $this->assertSame([], $cari('merek'));

        // Tidak ada yang cocok.
        $kosong = $this->ambil('/api/v1/products?q=tidakada')->assertOk();
        $this->assertSame([], $kosong->json('data'));
        $this->assertSame(0, $kosong->json('meta.total'));

        // Tanda % dan _ dianggap huruf biasa, bukan pola.
        $this->assertSame(['100% Katun'], $cari('%'));
        $this->assertSame([], $cari('_'));
        $this->assertSame(['100% Katun'], $cari('100%'));

        // Terlalu panjang, dan kata yang berlebih (hanya 5 kata pertama dipakai) tidak membuat error.
        $this->ambil('/api/v1/products?q=' . str_repeat('a', 101))->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->ambil('/api/v1/products?q=' . urlencode('kaos polos merah x y z'))->assertOk();
    }

    // ------------------------------------------------------------------ FILTER

    public function test_filter_kategori_brand_toko_dan_stok(): void
    {
        $toko1      = $this->toko();
        $toko2      = $this->toko();
        $tokoBlokir = $this->toko('suspended');

        $fashion     = $this->kategori(['name' => 'Fashion', 'slug' => 'fashion']);
        $pria        = $this->kategori(['name' => 'Pria', 'slug' => 'fashion-pria', 'parent_id' => $fashion->id]);
        $wanita      = $this->kategori(['name' => 'Wanita', 'slug' => 'fashion-wanita', 'parent_id' => $fashion->id, 'is_active' => false]);
        $elektronik  = $this->kategori(['name' => 'Elektronik', 'slug' => 'elektronik']);
        $rahasia     = $this->kategori(['name' => 'Rahasia', 'slug' => 'rahasia', 'is_active' => false]);
        $rahasiaAnak = $this->kategori(['name' => 'Rahasia Anak', 'slug' => 'rahasia-anak', 'parent_id' => $rahasia->id]);

        $brandA = $this->merek(['name' => 'Merek A', 'slug' => 'merek-a']);
        $brandB = $this->merek(['name' => 'Merek B', 'slug' => 'merek-b', 'is_active' => false]);

        $this->produk($toko1, $fashion, 'P1', ['brand' => $brandA]);
        $this->produk($toko1, $pria, 'P2');
        $this->produk($toko1, $wanita, 'P3');                                   // kategorinya nonaktif: tersembunyi
        $this->produk($toko2, $elektronik, 'P4', ['brand' => $brandA]);
        $this->produk($toko2, $rahasiaAnak, 'P5');
        $this->produk($toko2, $elektronik, 'Habis', ['stok' => 0]);
        $this->produk($toko2, $elektronik, 'Varian Mati', ['varian_aktif' => false, 'stok' => 5]);
        $this->produk($tokoBlokir, $elektronik, 'P8');

        $daftar = fn(string $qs) => $this->nama($this->ambil('/api/v1/products?' . $qs)->assertOk());

        // Kategori utama mencakup subkategorinya; subkategori hanya miliknya.
        $this->assertSame(['P2', 'P1'], $daftar('category=fashion'));
        $this->assertSame(['P2'], $daftar('category=fashion-pria'));
        $this->assertSame(['Varian Mati', 'Habis', 'P4'], $daftar('category=elektronik'));

        // Kategori tidak ada, nonaktif, atau induknya nonaktif: 404. Bentuk slug salah: 422.
        foreach (['tidak-ada', 'fashion-wanita', 'rahasia', 'rahasia-anak'] as $slug) {
            $this->ambil("/api/v1/products?category={$slug}")->assertStatus(404)->assertJson(['success' => false]);
        }
        $this->ambil('/api/v1/products?category=Fashion')->assertStatus(422)->assertJsonValidationErrors(['category']);

        // Brand.
        $this->assertSame(['P4', 'P1'], $daftar('brand=merek-a'));
        $this->ambil('/api/v1/products?brand=merek-b')->assertStatus(404);
        $this->ambil('/api/v1/products?brand=tidak-ada')->assertStatus(404);

        // Toko.
        $this->assertSame(['P2', 'P1'], $daftar('store=' . $toko1->slug));
        $this->ambil('/api/v1/products?store=' . $tokoBlokir->slug)->assertStatus(404);
        $this->ambil('/api/v1/products?store=tidak-ada')->assertStatus(404);

        // Stok: hanya yang punya varian aktif bersetok.
        $this->assertSame(['P4'], $daftar('category=elektronik&in_stock=1'));
        $this->assertSame(['P4'], $daftar('category=elektronik&in_stock=true'));
        $this->assertSame(['Varian Mati', 'Habis', 'P4'], $daftar('category=elektronik&in_stock=0'));

        // Kombinasi filter.
        $this->assertSame(['P1'], $daftar('category=fashion&brand=merek-a'));
        $this->assertSame(['P4'], $daftar('store=' . $toko2->slug . '&category=elektronik&in_stock=1'));
        $this->assertSame([], $daftar('store=' . $toko1->slug . '&category=elektronik'));
    }

    public function test_filter_harga_memakai_harga_terendah_dan_menolak_nilai_tidak_valid(): void
    {
        $toko     = $this->toko();
        $kategori = $this->kategori();

        foreach ([50000, 100000, 150000, 200000] as $harga) {
            $this->produk($toko, $kategori, 'H' . ($harga / 1000), ['harga' => $harga]);
        }

        $daftar = fn(string $qs) => $this->nama($this->ambil('/api/v1/products?' . $qs)->assertOk());

        $this->assertSame(['H200', 'H150', 'H100'], $daftar('min_price=100000'));
        $this->assertSame(['H150', 'H100', 'H50'], $daftar('max_price=150000'));
        $this->assertSame(['H150', 'H100'], $daftar('min_price=100000&max_price=150000'));
        $this->assertSame(['H100'], $daftar('min_price=100000&max_price=100000'));
        $this->assertSame([], $daftar('min_price=300000'));
        $this->assertSame(['H200', 'H150', 'H100', 'H50'], $daftar('min_price=0'));

        $this->ambil('/api/v1/products?min_price=200000&max_price=100000')
            ->assertStatus(422)->assertJsonValidationErrors(['max_price']);

        foreach (['min_price=abc', 'min_price=-1', 'min_price=1000000001', 'max_price=1.5', 'max_price=abc'] as $salah) {
            $this->ambil("/api/v1/products?{$salah}")->assertStatus(422)->assertJson(['success' => false]);
        }
    }

    // -------------------------------------------------------------- PENGURUTAN

    public function test_pengurutan_dan_urutan_pasti_saat_nilai_sama(): void
    {
        $toko     = $this->toko();
        $kategori = $this->kategori();

        // Urutan pembuatan A, B, C, D (id naik). Waktu tayang dan nilai lain sengaja berbeda.
        $this->produk($toko, $kategori, 'A', ['harga' => 300000, 'sold' => 5, 'rating' => 4.5, 'rating_count' => 10, 'published_at' => Carbon::parse('2026-02-01')]);
        $this->produk($toko, $kategori, 'B', ['harga' => 100000, 'sold' => 50, 'rating' => 4.9, 'rating_count' => 2, 'published_at' => Carbon::parse('2026-03-01')]);
        $this->produk($toko, $kategori, 'C', ['harga' => 200000, 'sold' => 0, 'rating' => 0, 'rating_count' => 0, 'published_at' => Carbon::parse('2026-04-01')]);
        $this->produk($toko, $kategori, 'D', ['harga' => 200000, 'sold' => 50, 'rating' => 4.9, 'rating_count' => 20, 'published_at' => Carbon::parse('2026-01-15')]);

        $urut = fn(string $sort) => $this->nama($this->ambil("/api/v1/products?sort={$sort}")->assertOk());

        $this->assertSame(['C', 'B', 'A', 'D'], $urut('newest'));
        $this->assertSame(['D', 'B', 'A', 'C'], $urut('best_selling'));   // B dan D sama (50): yang terbaru (id besar) dulu
        $this->assertSame(['B', 'D', 'C', 'A'], $urut('price_asc'));      // C dan D sama (200 rb): D dulu
        $this->assertSame(['A', 'D', 'C', 'B'], $urut('price_desc'));
        $this->assertSame(['D', 'B', 'A', 'C'], $urut('rating'));         // B dan D sama (4,9): ulasan lebih banyak dulu

        // Tanpa parameter sort: sama dengan newest.
        $this->assertSame(['C', 'B', 'A', 'D'], $this->nama($this->ambil('/api/v1/products')->assertOk()));

        foreach (['NEWEST', 'acak', 'price'] as $salah) {
            $this->ambil("/api/v1/products?sort={$salah}")->assertStatus(422)->assertJsonValidationErrors(['sort']);
        }
    }

    // --------------------------------------------------------------- PAGINASI

    public function test_paginasi_tidak_menduplikasi_atau_melewatkan_produk(): void
    {
        $toko     = $this->toko();
        $kategori = $this->kategori();

        foreach (range(1, 5) as $n) {
            $this->produk($toko, $kategori, "Item {$n}");
        }

        $semua = [];

        foreach ([1, 2, 3] as $halaman) {
            $res = $this->ambil("/api/v1/products?per_page=2&page={$halaman}")->assertOk();
            $this->assertSame(
                ['current_page' => $halaman, 'per_page' => 2, 'total' => 5, 'last_page' => 3],
                $res->json('meta')
            );
            $semua = array_merge($semua, $this->nama($res));
        }

        $this->assertSame(['Item 5', 'Item 4', 'Item 3', 'Item 2', 'Item 1'], $semua);

        // Halaman di luar jangkauan: kosong, bukan error.
        $lewat = $this->ambil('/api/v1/products?per_page=2&page=9')->assertOk();
        $this->assertSame([], $lewat->json('data'));
        $this->assertSame(5, $lewat->json('meta.total'));

        foreach (['per_page=0', 'per_page=51', 'per_page=abc', 'in_stock=abc', 'brand=Bad%20Slug', 'store=x_y'] as $salah) {
            $this->ambil("/api/v1/products?{$salah}")->assertStatus(422)->assertJson(['success' => false]);
        }
    }

    // ----------------------------------------------------------------- BERANDA

    public function test_beranda_kosong_tetap_memuat_semua_bagian(): void
    {
        $this->ambil('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.banners', [])
            ->assertJsonPath('data.categories', [])
            ->assertJsonPath('data.featured', [])
            ->assertJsonPath('data.new_arrivals', [])
            ->assertJsonPath('data.best_sellers', []);
    }

    public function test_beranda_memuat_banner_kategori_dan_tiga_daftar_produk(): void
    {
        $toko       = $this->toko();
        $fashion    = $this->kategori(['name' => 'Fashion', 'slug' => 'fashion', 'sort_order' => 1]);
        $elektronik = $this->kategori(['name' => 'Elektronik', 'slug' => 'elektronik', 'sort_order' => 2]);
        $this->kategori(['name' => 'Pria', 'slug' => 'fashion-pria', 'parent_id' => $fashion->id]);
        $mati       = $this->kategori(['name' => 'Arsip', 'slug' => 'arsip', 'is_active' => false]);

        Banner::create(['title' => 'Live Dua', 'image_path' => 'banners/b2.jpg', 'sort_order' => 2, 'link_type' => 'product', 'link_value' => 'sepatu-lari']);
        Banner::create(['title' => 'Live Satu', 'image_path' => 'banners/b1.jpg', 'sort_order' => 1]);
        Banner::create(['title' => 'Nonaktif', 'image_path' => 'banners/x.jpg', 'is_active' => false]);
        Banner::create(['title' => 'Kedaluwarsa', 'image_path' => 'banners/x.jpg', 'ends_at' => now()->subDay()]);
        Banner::create(['title' => 'Belum Mulai', 'image_path' => 'banners/x.jpg', 'starts_at' => now()->addDay()]);
        Banner::create(['title' => 'Posisi Lain', 'image_path' => 'banners/x.jpg', 'position' => 'popup']);

        foreach (range(1, 12) as $n) {
            $terjual = [3 => 30, 7 => 10, 9 => 20][$n] ?? 0;
            $this->produk($toko, $fashion, "P{$n}", ['sold' => $terjual, 'featured' => in_array($n, [2, 5], true)]);
        }

        $this->produk($toko, $fashion, 'FD', ['status' => 'draft', 'featured' => true]);       // draf: tersembunyi
        $this->produk($toko, $mati, 'FK', ['featured' => true, 'sold' => 99]);                  // kategori nonaktif: tersembunyi

        $res = $this->ambil('/api/v1/home')->assertOk()->assertJson(['success' => true]);

        // Banner: hanya yang tayang di posisi beranda, berurutan, tanpa data internal.
        $this->assertSame(['Live Satu', 'Live Dua'], collect($res->json('data.banners'))->pluck('title')->all());
        $this->assertNotNull($res->json('data.banners.0.image_url'));
        $this->assertSame(['none', null], [$res->json('data.banners.0.link_type'), $res->json('data.banners.0.link_value')]);
        $this->assertSame(['product', 'sepatu-lari'], [$res->json('data.banners.1.link_type'), $res->json('data.banners.1.link_value')]);
        foreach (['id', 'is_active', 'sort_order', 'position'] as $kolom) {
            $res->assertJsonMissingPath("data.banners.0.{$kolom}");
        }

        // Kategori: hanya kategori utama yang aktif, berurutan.
        $this->assertSame(['Fashion', 'Elektronik'], collect($res->json('data.categories'))->pluck('name')->all());
        $res->assertJsonMissingPath('data.categories.0.children');
        $res->assertJsonMissingPath('data.categories.0.is_active');

        // Tiga daftar produk.
        $this->assertSame(['P5', 'P2'], collect($res->json('data.featured'))->pluck('name')->all());
        $this->assertSame(
            ['P12', 'P11', 'P10', 'P9', 'P8', 'P7', 'P6', 'P5', 'P4', 'P3'],
            collect($res->json('data.new_arrivals'))->pluck('name')->all()
        );
        $this->assertSame(['P3', 'P9', 'P7'], collect($res->json('data.best_sellers'))->pluck('name')->all());

        $res->assertJsonStructure(['data' => ['new_arrivals' => ['*' => ['id', 'name', 'slug', 'price_min', 'store' => ['name']]]]]);
    }

    // ----------------------------------------------------------- PRODUK TERKAIT

    public function test_produk_terkait_sekategori_terlaris_dulu_dan_dibatasi(): void
    {
        $toko       = $this->toko();
        $tokoBlokir = $this->toko('suspended');
        $x          = $this->kategori(['slug' => 'kategori-x']);
        $y          = $this->kategori(['slug' => 'kategori-y']);
        $z          = $this->kategori(['slug' => 'kategori-z']);

        $target = $this->produk($toko, $x, 'Target');
        $this->produk($toko, $x, 'R2', ['sold' => 5]);
        $this->produk($toko, $x, 'R3', ['sold' => 20]);
        $this->produk($toko, $x, 'Draf', ['status' => 'draft', 'sold' => 99]);
        $this->produk($tokoBlokir, $x, 'Toko Blokir', ['sold' => 99]);
        $this->produk($toko, $y, 'Lain Kategori', ['sold' => 99]);

        $res = $this->ambil("/api/v1/products/{$target->slug}/related")->assertOk()->assertJson(['success' => true]);

        $this->assertSame(['R3', 'R2'], $this->nama($res));          // tanpa produk itu sendiri, draf, toko diblokir, dan kategori lain
        $res->assertJsonMissingPath('data.0.status');

        // Dibatasi 10 produk (kategori sendiri agar tidak bercampur dengan "Lain Kategori").
        $targetZ = $this->produk($toko, $z, 'Target Z');
        foreach (range(1, 11) as $n) {
            $this->produk($toko, $z, "Saudara {$n}", ['sold' => $n]);
        }

        $banyak = $this->ambil("/api/v1/products/{$targetZ->slug}/related")->assertOk();
        $this->assertCount(10, $banyak->json('data'));
        $this->assertSame('Saudara 11', $banyak->json('data.0.name'));

        // Produk acuan tidak tampil untuk publik, tidak ada, atau slug salah bentuk: 404.
        $draf = $this->produk($toko, $x, 'Draf Lagi', ['status' => 'draft']);
        $this->ambil("/api/v1/products/{$draf->slug}/related")->assertStatus(404);
        $this->ambil('/api/v1/products/tidak-ada/related')->assertStatus(404);
        $this->ambil('/api/v1/products/Slug-Besar/related')->assertStatus(404);
    }

    // ------------------------------------------------------------- HALAMAN TOKO

    public function test_halaman_toko_publik_tanpa_data_internal_dan_hanya_toko_aktif(): void
    {
        $toko = $this->toko('active', [
            'name'        => 'Toko Budi',
            'slug'        => 'toko-budi',
            'description' => 'Perlengkapan olahraga',
            'logo_path'   => 'stores/logos/logo.jpg',
        ]);
        $toko->forceFill(['rating_avg' => 4.8, 'rating_count' => 12, 'followers_count' => 34, 'closed_note' => 'Libur sampai Senin', 'is_open' => false])->save();
        $toko->operatingHours()->create(['day_of_week' => 3, 'open_time' => '09:00', 'close_time' => '15:00']);
        $toko->operatingHours()->create(['day_of_week' => 1, 'open_time' => '08:00', 'close_time' => '17:00']);
        $toko->operatingHours()->create(['day_of_week' => 7, 'is_closed' => true]);

        $kategori     = $this->kategori();
        $kategoriMati = $this->kategori(['is_active' => false]);

        $this->produk($toko, $kategori, 'Tampil 1');
        $this->produk($toko, $kategori, 'Tampil 2');
        $this->produk($toko, $kategori, 'Draf', ['status' => 'draft']);
        $this->produk($toko, $kategoriMati, 'Kategori Mati');
        $this->produk($toko, $kategori, 'Dihapus', ['dihapus' => true]);

        $res = $this->ambil('/api/v1/stores/toko-budi')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => [
                'name'            => 'Toko Budi',
                'slug'            => 'toko-budi',
                'description'     => 'Perlengkapan olahraga',
                'city'            => 'Sleman',
                'province'        => 'DI Yogyakarta',
                'is_open'         => false,
                'closed_note'     => 'Libur sampai Senin',
                'rating_avg'      => 4.8,
                'rating_count'    => 12,
                'followers_count' => 34,
                'products_count'  => 2,
                'banner_url'      => null,
            ]]);

        $this->assertSame(36, strlen($res->json('data.id')));
        $this->assertNotNull($res->json('data.logo_url'));
        $this->assertNotNull($res->json('data.joined_at'));
        $this->assertSame([1, 3, 7], collect($res->json('data.operating_hours'))->pluck('day_of_week')->all());
        $this->assertSame(['08:00', '17:00'], [$res->json('data.operating_hours.0.open_time'), $res->json('data.operating_hours.0.close_time')]);
        $this->assertTrue($res->json('data.operating_hours.2.is_closed'));
        $this->assertNull($res->json('data.operating_hours.2.open_time'));

        foreach (['phone', 'street', 'district', 'postal_code', 'latitude', 'longitude', 'owner_id', 'biteship_area_id', 'commission_rate', 'status', 'deleted_at'] as $kolom) {
            $res->assertJsonMissingPath("data.{$kolom}");
        }
        $this->assertStringNotContainsString('Jl. Rahasia', $res->getContent());
        $this->assertStringNotContainsString('628123456789', $res->getContent());

        // Toko ditangguhkan atau ditutup, tidak ada, atau slug salah bentuk: 404.
        $this->toko('suspended', ['slug' => 'toko-blokir']);
        $this->toko('closed', ['slug' => 'toko-tutup']);

        foreach (['toko-blokir', 'toko-tutup', 'tidak-ada', 'Toko-Budi'] as $slug) {
            $this->ambil("/api/v1/stores/{$slug}")->assertStatus(404)->assertJson(['success' => false]);
        }
    }
}
