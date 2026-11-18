<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menguji kategori dan brand (publik dan admin) lewat HTTP sungguhan
 * (SQLite memori dan disk palsu, tidak menyentuh MySQL maupun file asli).
 */
class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private const UUID_ASING = '00000000-0000-4000-8000-000000000000';

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RoleSeeder::class);
    }

    private function panggil(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->json($method, $uri, $data);
    }

    private function kirimGambar(string $uri, ?UploadedFile $file, string $token)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->post(
            $uri,
            $file === null ? [] : ['image' => $file],
            ['Accept' => 'application/json']
        );
    }

    /** Membuat pengguna langsung di database. Mengembalikan [pengguna, token]. */
    private function pengguna(array $roles = ['customer']): array
    {
        $this->urut++;

        $user = User::create([
            'name'     => "User {$this->urut}",
            'email'    => "user{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return [$user, $user->createToken('test', ['*'], now()->addDay())->plainTextToken];
    }

    private function tokenAdmin(): string
    {
        [, $token] = $this->pengguna(['customer', 'admin']);

        return $token;
    }

    private function kategori(array $override = []): Category
    {
        $this->urut++;

        return Category::create(array_merge([
            'name' => "Kategori {$this->urut}",
            'slug' => "kategori-{$this->urut}",
        ], $override));
    }

    private function merek(array $override = []): Brand
    {
        $this->urut++;

        return Brand::create(array_merge([
            'name' => "Brand {$this->urut}",
            'slug' => "brand-{$this->urut}",
        ], $override));
    }

    private function produk(Category $kategori, ?Brand $brand = null): Product
    {
        [$pemilik] = $this->pengguna(['customer', 'seller']);

        $toko = new Store([
            'name' => "Toko {$this->urut}",
            'slug' => "toko-{$this->urut}",
            'phone' => '628123456789',
            'province' => 'DI Yogyakarta',
            'city' => 'Sleman',
            'district' => 'Depok',
            'postal_code' => '55281',
            'street' => 'Jl. Contoh No. 1',
        ]);
        $toko->owner_id = $pemilik->id;
        $toko->save();

        $produk = new Product([
            'name'        => 'Kaos Polos',
            'slug'        => "kaos-polos-{$this->urut}",
            'category_id' => $kategori->id,
            'brand_id'    => $brand?->id,
        ]);
        $produk->store_id = $toko->id;
        $produk->save();

        return $produk;
    }

    // ---------------------------------------------------------- KATEGORI PUBLIK

    public function test_pohon_kategori_publik_hanya_yang_aktif_berurutan_dan_tanpa_data_internal(): void
    {
        $elektronik = $this->kategori(['name' => 'Elektronik', 'slug' => 'elektronik', 'sort_order' => 2]);
        $fashion    = $this->kategori(['name' => 'Fashion', 'slug' => 'fashion', 'sort_order' => 1]);
        $rahasia    = $this->kategori(['name' => 'Rahasia', 'slug' => 'rahasia', 'is_active' => false]);

        $this->kategori(['name' => 'Pria', 'slug' => 'fashion-pria', 'parent_id' => $fashion->id, 'sort_order' => 2]);
        $this->kategori(['name' => 'Wanita', 'slug' => 'fashion-wanita', 'parent_id' => $fashion->id, 'sort_order' => 1]);
        $this->kategori(['name' => 'Anak', 'slug' => 'fashion-anak', 'parent_id' => $fashion->id, 'is_active' => false]);
        $this->kategori(['name' => 'Anak Rahasia', 'slug' => 'rahasia-anak', 'parent_id' => $rahasia->id]);

        $dihapus = $this->kategori(['name' => 'Dihapus', 'slug' => 'dihapus']);
        $dihapus->delete();

        // Tanpa login.
        $res = $this->panggil('GET', '/api/v1/categories')->assertOk()->assertJson(['success' => true]);

        $this->assertSame(['fashion', 'elektronik'], collect($res->json('data'))->pluck('slug')->all());
        $this->assertSame(
            ['fashion-wanita', 'fashion-pria'],
            collect($res->json('data.0.children'))->pluck('slug')->all()
        );
        $this->assertSame([], $res->json('data.1.children'));

        $this->assertSame(36, strlen($res->json('data.0.id')));
        $this->assertFalse(is_numeric($res->json('data.0.id')));
        foreach (['is_active', 'products_count', 'parent_id', 'deleted_at'] as $kolom) {
            $res->assertJsonMissingPath("data.0.{$kolom}");
        }
        $this->assertNull($res->json('data.0.icon_url'));
    }

    public function test_detail_kategori_publik_menyertakan_induk_dan_anak_dan_menyembunyikan_yang_tidak_tayang(): void
    {
        $fashion = $this->kategori(['name' => 'Fashion', 'slug' => 'fashion']);
        $rahasia = $this->kategori(['name' => 'Rahasia', 'slug' => 'rahasia', 'is_active' => false]);

        $this->kategori(['name' => 'Pria', 'slug' => 'fashion-pria', 'parent_id' => $fashion->id, 'sort_order' => 1]);
        $this->kategori(['name' => 'Anak', 'slug' => 'fashion-anak', 'parent_id' => $fashion->id, 'is_active' => false]);
        $this->kategori(['name' => 'Anak Rahasia', 'slug' => 'rahasia-anak', 'parent_id' => $rahasia->id]);
        $this->kategori(['name' => 'Dihapus', 'slug' => 'dihapus'])->delete();

        $utama = $this->panggil('GET', '/api/v1/categories/fashion')->assertOk();
        $this->assertSame('fashion', $utama->json('data.slug'));
        $this->assertNull($utama->json('data.parent'));
        $this->assertSame(['fashion-pria'], collect($utama->json('data.children'))->pluck('slug')->all());

        $anak = $this->panggil('GET', '/api/v1/categories/fashion-pria')->assertOk();
        $this->assertSame('fashion', $anak->json('data.parent.slug'));
        $this->assertSame('Fashion', $anak->json('data.parent.name'));
        $this->assertSame([], $anak->json('data.children'));

        foreach (['rahasia', 'rahasia-anak', 'fashion-anak', 'dihapus', 'tidak-ada', 'Ada Spasi', 'HURUF_BESAR'] as $slug) {
            $this->panggil('GET', '/api/v1/categories/' . rawurlencode($slug))
                ->assertStatus(404)
                ->assertJson(['success' => false]);
        }
    }

    // ------------------------------------------------------------- BRAND PUBLIK

    public function test_brand_publik_hanya_yang_aktif_dengan_pencarian_aman_dan_paginasi(): void
    {
        $this->merek(['name' => 'Nexora Wear', 'slug' => 'nexora-wear']);
        $this->merek(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->merek(['name' => 'Beta', 'slug' => 'beta', 'is_active' => false]);
        $this->merek(['name' => '100% Katun', 'slug' => 'katun']);

        $res = $this->panggil('GET', '/api/v1/brands')->assertOk();

        $this->assertSame(['100% Katun', 'Alpha', 'Nexora Wear'], collect($res->json('data'))->pluck('name')->all());
        $this->assertSame(
            ['current_page' => 1, 'per_page' => 20, 'total' => 3, 'last_page' => 1],
            $res->json('meta')
        );
        foreach (['is_active', 'products_count'] as $kolom) {
            $res->assertJsonMissingPath("data.0.{$kolom}");
        }
        $this->assertNull($res->json('data.0.logo_url'));
        $this->assertSame(36, strlen($res->json('data.0.id')));

        // Pencarian.
        $this->assertSame(['Nexora Wear'], collect($this->panggil('GET', '/api/v1/brands?q=wear')->json('data'))->pluck('name')->all());
        $this->assertSame([], $this->panggil('GET', '/api/v1/brands?q=beta')->json('data'));   // nonaktif tidak tampil

        // Tanda % dan _ dianggap huruf biasa, bukan pola.
        $this->assertSame(['100% Katun'], collect($this->panggil('GET', '/api/v1/brands?q=' . urlencode('%'))->json('data'))->pluck('name')->all());
        $this->assertSame([], $this->panggil('GET', '/api/v1/brands?q=' . urlencode('_'))->json('data'));

        // Paginasi.
        $halaman = $this->panggil('GET', '/api/v1/brands?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $halaman->json('data'));
        $this->assertSame(
            ['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2],
            $halaman->json('meta')
        );

        $this->panggil('GET', '/api/v1/brands?per_page=100')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->panggil('GET', '/api/v1/brands?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    // --------------------------------------------------------------- AKSES ADMIN

    public function test_endpoint_admin_katalog_hanya_untuk_admin(): void
    {
        [, $tokenPembeli] = $this->pengguna(['customer']);
        [, $tokenSeller]  = $this->pengguna(['customer', 'seller']);
        $u                = self::UUID_ASING;

        $daftar = [
            ['GET', '/api/v1/admin/categories'],
            ['POST', '/api/v1/admin/categories'],
            ['PATCH', "/api/v1/admin/categories/{$u}"],
            ['DELETE', "/api/v1/admin/categories/{$u}"],
            ['POST', "/api/v1/admin/categories/{$u}/images/icon"],
            ['DELETE', "/api/v1/admin/categories/{$u}/images/icon"],
            ['GET', '/api/v1/admin/brands'],
            ['POST', '/api/v1/admin/brands'],
            ['PATCH', "/api/v1/admin/brands/{$u}"],
            ['DELETE', "/api/v1/admin/brands/{$u}"],
            ['POST', "/api/v1/admin/brands/{$u}/logo"],
            ['DELETE', "/api/v1/admin/brands/{$u}/logo"],
        ];

        foreach ($daftar as [$method, $uri]) {
            $this->panggil($method, $uri)->assertStatus(401);
            $this->panggil($method, $uri, ['name' => 'X'], $tokenPembeli)->assertStatus(403);
            $this->panggil($method, $uri, ['name' => 'X'], $tokenSeller)->assertStatus(403);
        }

        [, $tokenAdmin] = $this->pengguna(['customer', 'admin']);
        [, $tokenSuper] = $this->pengguna(['customer', 'super_admin']);

        foreach ([$tokenAdmin, $tokenSuper] as $token) {
            $this->panggil('GET', '/api/v1/admin/categories', [], $token)->assertOk();
            $this->panggil('GET', '/api/v1/admin/brands', [], $token)->assertOk();
        }

        $this->assertSame(0, Category::count());
        $this->assertSame(0, Brand::count());
    }

    // ---------------------------------------------------------- KATEGORI: BUAT

    public function test_admin_membuat_kategori_dengan_slug_otomatis_unik_dan_input_sensitif_diabaikan(): void
    {
        $token = $this->tokenAdmin();

        $res = $this->panggil('POST', '/api/v1/admin/categories', [
            'name'       => 'Elektronik Rumah',
            'sort_order' => 3,
            // Semua di bawah ini harus diabaikan:
            'id'         => 5,
            'uuid'       => self::UUID_ASING,
            'icon_path'  => 'x/y.jpg',
            'deleted_at' => now()->toDateTimeString(),
        ], $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'data' => [
                'name'           => 'Elektronik Rumah',
                'slug'           => 'elektronik-rumah',
                'sort_order'     => 3,
                'is_active'      => true,
                'parent'         => null,
                'products_count' => 0,
                'icon_url'       => null,
            ]]);

        $this->assertSame(36, strlen($res->json('data.id')));
        $this->assertNotSame(self::UUID_ASING, $res->json('data.id'));

        $pertama = Category::first();
        $this->assertNull($pertama->icon_path);
        $this->assertNull($pertama->deleted_at);

        // Nama sama: slug otomatis diberi akhiran agar tetap unik.
        $kedua = $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Elektronik Rumah'], $token)->assertStatus(201);
        $this->assertStringStartsWith('elektronik-rumah-', $kedua->json('data.slug'));
        $this->assertSame(2, Category::count());

        // Slug eksplisit, termasuk huruf besar yang dijadikan kecil.
        $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Rumah Tangga', 'slug' => 'rumah-tangga'], $token)
            ->assertStatus(201)->assertJson(['data' => ['slug' => 'rumah-tangga']]);
        $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Kamera', 'slug' => 'KAMERA'], $token)
            ->assertStatus(201)->assertJson(['data' => ['slug' => 'kamera']]);

        $this->assertSame(4, ActivityLog::where('action', 'admin.category_created')->count());

        // Slug kembar dan bentuk slug yang salah.
        $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Lain', 'slug' => 'rumah-tangga'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);

        foreach (['rumah_tangga', 'rumah tangga', '-rumah', 'rumah-', 'rumah--tangga', 'rumah!'] as $salah) {
            $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Lain', 'slug' => $salah], $token)
                ->assertStatus(422)->assertJsonValidationErrors(['slug']);
        }

        // Slug milik kategori yang sudah dihapus tetap terkunci.
        $this->kategori(['slug' => 'arsip'])->delete();
        $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Arsip Baru', 'slug' => 'arsip'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);

        // Data tidak valid lainnya.
        $kasus = [
            [[], 'name'],
            [['name' => 'A'], 'name'],
            [['name' => 'Valid', 'sort_order' => -1], 'sort_order'],
            [['name' => 'Valid', 'sort_order' => 70000], 'sort_order'],
            [['name' => 'Valid', 'sort_order' => 'abc'], 'sort_order'],
            [['name' => 'Valid', 'is_active' => 'ya'], 'is_active'],
            [['name' => 'Valid', 'parent_id' => 'bukan-uuid'], 'parent_id'],
        ];

        foreach ($kasus as [$data, $kolom]) {
            $this->panggil('POST', '/api/v1/admin/categories', $data, $token)
                ->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors([$kolom]);
        }
    }

    public function test_admin_membuat_subkategori_dengan_batas_dua_tingkat(): void
    {
        $token = $this->tokenAdmin();

        $induk = $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Fashion'], $token)->assertStatus(201)->json('data.id');

        $anak = $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Pria', 'parent_id' => $induk], $token)
            ->assertStatus(201)
            ->assertJson(['data' => ['parent' => ['id' => $induk, 'slug' => 'fashion']]])
            ->json('data.id');

        // Tingkat ketiga ditolak.
        $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Kaos', 'parent_id' => $anak], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);

        // Induk tidak ada, atau sudah dihapus.
        $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Kaos', 'parent_id' => self::UUID_ASING], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);

        $dihapus = $this->kategori();
        $dihapus->delete();
        $this->panggil('POST', '/api/v1/admin/categories', ['name' => 'Kaos', 'parent_id' => $dihapus->uuid], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);

        $this->assertSame(2, Category::count());
    }

    // ---------------------------------------------------------- KATEGORI: UBAH

    public function test_admin_mengubah_kategori_sebagian_slug_tetap_dan_struktur_dijaga(): void
    {
        $token = $this->tokenAdmin();

        $a = $this->kategori(['name' => 'Fashion', 'slug' => 'fashion']);
        $b = $this->kategori(['name' => 'Elektronik', 'slug' => 'elektronik']);
        $c = $this->kategori(['name' => 'Pria', 'slug' => 'pria', 'parent_id' => $a->id]);

        $url = fn(Category $k) => "/api/v1/admin/categories/{$k->uuid}";

        // Nama diganti, slug tetap.
        $this->panggil('PATCH', $url($c), ['name' => 'Pria Dewasa'], $token)
            ->assertOk()
            ->assertJson(['data' => ['name' => 'Pria Dewasa', 'slug' => 'pria', 'parent' => ['slug' => 'fashion']]]);

        // Pindah induk.
        $this->panggil('PATCH', $url($c), ['parent_id' => $b->uuid], $token)
            ->assertOk()->assertJson(['data' => ['parent' => ['slug' => 'elektronik']]]);
        $this->assertSame($b->id, $c->fresh()->parent_id);

        // Dijadikan kategori utama.
        $this->panggil('PATCH', $url($c), ['parent_id' => null], $token)
            ->assertOk()->assertJson(['data' => ['parent' => null]]);
        $this->assertNull($c->fresh()->parent_id);

        // Tidak boleh menjadi induk dirinya sendiri.
        $this->panggil('PATCH', $url($a), ['parent_id' => $a->uuid], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);

        // Kategori yang punya subkategori tidak boleh dijadikan subkategori.
        $this->kategori(['name' => 'Sub', 'slug' => 'sub', 'parent_id' => $a->id]);
        $this->panggil('PATCH', $url($a), ['parent_id' => $b->uuid], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
        $this->assertNull($a->fresh()->parent_id);

        // Status dan urutan.
        $this->panggil('PATCH', $url($c), ['is_active' => false, 'sort_order' => 5], $token)
            ->assertOk()->assertJson(['data' => ['is_active' => false, 'sort_order' => 5]]);
        $this->assertFalse($c->fresh()->is_active);
        $this->assertSame(5, $c->fresh()->sort_order);

        // Slug: kembar ditolak, milik sendiri boleh, baru boleh, null berarti tidak diubah.
        $this->panggil('PATCH', $url($c), ['slug' => 'fashion'], $token)->assertStatus(422)->assertJsonValidationErrors(['slug']);
        $this->panggil('PATCH', $url($c), ['slug' => 'PRIA'], $token)->assertOk()->assertJson(['data' => ['slug' => 'pria']]);
        $this->panggil('PATCH', $url($c), ['slug' => 'pria-baru'], $token)->assertOk()->assertJson(['data' => ['slug' => 'pria-baru']]);
        $this->panggil('PATCH', $url($c), ['slug' => null], $token)->assertOk()->assertJson(['data' => ['slug' => 'pria-baru']]);

        // Isi kosong: tidak ada yang berubah.
        $this->panggil('PATCH', $url($c), [], $token)->assertOk()->assertJson(['data' => ['name' => 'Pria Dewasa']]);

        // Tidak valid.
        $this->panggil('PATCH', $url($c), ['name' => ''], $token)->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->panggil('PATCH', $url($c), ['sort_order' => -5], $token)->assertStatus(422)->assertJsonValidationErrors(['sort_order']);

        // Tidak ada, atau bukan UUID.
        $this->panggil('PATCH', '/api/v1/admin/categories/' . self::UUID_ASING, ['name' => 'Nama Valid'], $token)->assertStatus(404);
        $this->panggil('PATCH', '/api/v1/admin/categories/abc', ['name' => 'Nama Valid'], $token)->assertStatus(404);

        $this->assertGreaterThanOrEqual(8, ActivityLog::where('action', 'admin.category_updated')->count());
    }

    // --------------------------------------------------------- KATEGORI: HAPUS

    public function test_kategori_yang_punya_subkategori_atau_produk_tidak_bisa_dihapus(): void
    {
        $token = $this->tokenAdmin();

        $induk  = $this->kategori(['slug' => 'induk']);
        $anak   = $this->kategori(['slug' => 'anak', 'parent_id' => $induk->id]);
        $produk = $this->produk($anak);

        $this->panggil('DELETE', "/api/v1/admin/categories/{$induk->uuid}", [], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['category']);

        $this->panggil('DELETE', "/api/v1/admin/categories/{$anak->uuid}", [], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['category']);

        // Produk yang sudah dihapus lunak tetap menahan penghapusan kategori.
        $produk->delete();
        $this->panggil('DELETE', "/api/v1/admin/categories/{$anak->uuid}", [], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['category']);

        $this->assertSame(2, Category::count());

        // Kategori kosong bisa dihapus (hapus lunak) dan langsung hilang dari publik dan admin.
        $kosong = $this->kategori(['slug' => 'kosong']);

        $this->panggil('DELETE', "/api/v1/admin/categories/{$kosong->uuid}", [], $token)
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSoftDeleted('categories', ['id' => $kosong->id]);
        $this->panggil('GET', '/api/v1/categories/kosong')->assertStatus(404);

        $daftar = $this->panggil('GET', '/api/v1/admin/categories', [], $token)->assertOk();
        $this->assertSame(['induk'], collect($daftar->json('data'))->pluck('slug')->all());

        $this->panggil('DELETE', "/api/v1/admin/categories/{$kosong->uuid}", [], $token)->assertStatus(404);
        $this->assertSame(1, ActivityLog::where('action', 'admin.category_deleted')->count());
    }

    // ------------------------------------------------------ KATEGORI: DAFTAR ADMIN

    public function test_daftar_admin_menyertakan_yang_nonaktif_dan_jumlah_produk(): void
    {
        $token = $this->tokenAdmin();

        $fashion = $this->kategori(['name' => 'Fashion', 'slug' => 'fashion', 'sort_order' => 1]);
        $this->kategori(['name' => 'Elektronik', 'slug' => 'elektronik', 'sort_order' => 2, 'is_active' => false]);
        $this->kategori(['name' => 'Pria', 'slug' => 'pria', 'parent_id' => $fashion->id, 'is_active' => false]);

        $this->produk($fashion);
        $this->produk($fashion);

        $res = $this->panggil('GET', '/api/v1/admin/categories', [], $token)->assertOk();

        $this->assertSame(['fashion', 'elektronik'], collect($res->json('data'))->pluck('slug')->all());
        $this->assertSame(2, $res->json('data.0.products_count'));
        $this->assertTrue($res->json('data.0.is_active'));
        $this->assertSame('pria', $res->json('data.0.children.0.slug'));
        $this->assertFalse($res->json('data.0.children.0.is_active'));
        $this->assertSame(0, $res->json('data.0.children.0.products_count'));
        $this->assertFalse($res->json('data.1.is_active'));
        $this->assertSame([], $res->json('data.1.children'));
    }

    // ---------------------------------------------------------- KATEGORI: GAMBAR

    public function test_ikon_dan_gambar_kategori_diproses_diganti_dan_dihapus(): void
    {
        $token = $this->tokenAdmin();
        $k     = $this->kategori(['slug' => 'fashion']);
        $uri   = "/api/v1/admin/categories/{$k->uuid}/images";
        $disk  = Storage::disk('public');

        // Ikon PNG besar diperkecil menjadi JPEG dengan sisi terpanjang 256.
        $res = $this->kirimGambar("{$uri}/icon", UploadedFile::fake()->image('icon.png', 600, 600), $token)
            ->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull($res->json('data.icon_url'));

        $ikon1 = $k->fresh()->icon_path;
        $this->assertStringStartsWith('categories/icons/', $ikon1);
        $this->assertStringEndsWith('.jpg', $ikon1);
        $info = getimagesizefromstring($disk->get($ikon1));
        $this->assertSame([IMAGETYPE_JPEG, 256, 256], [$info[2], $info[0], $info[1]]);

        // Gambar: sisi terpanjang 1200.
        $this->kirimGambar("{$uri}/image", UploadedFile::fake()->image('gambar.jpg', 3000, 1000), $token)->assertOk();
        $gambar = $k->fresh()->image_path;
        $this->assertStringStartsWith('categories/images/', $gambar);
        $info = getimagesizefromstring($disk->get($gambar));
        $this->assertSame([1200, 400], [$info[0], $info[1]]);

        // Ikon diganti: berkas lama terhapus, gambar kecil tidak diperbesar, gambar tidak tersentuh.
        $this->kirimGambar("{$uri}/icon", UploadedFile::fake()->image('baru.jpg', 100, 100), $token)->assertOk();
        $ikon2 = $k->fresh()->icon_path;
        $this->assertNotSame($ikon1, $ikon2);
        $disk->assertMissing($ikon1);
        $disk->assertExists($ikon2);
        $disk->assertExists($gambar);
        $info = getimagesizefromstring($disk->get($ikon2));
        $this->assertSame([100, 100], [$info[0], $info[1]]);

        // Dihapus satu per satu.
        $this->panggil('DELETE', "{$uri}/icon", [], $token)->assertOk()->assertJson(['data' => ['icon_url' => null]]);
        $this->assertNull($k->fresh()->icon_path);
        $this->assertCount(1, $disk->allFiles());

        $this->panggil('DELETE', "{$uri}/image", [], $token)->assertOk()->assertJson(['data' => ['image_url' => null]]);
        $this->assertSame([], $disk->allFiles());

        $this->assertSame(3, ActivityLog::where('action', 'admin.category_image_changed')->count());
        $this->assertSame(2, ActivityLog::where('action', 'admin.category_image_removed')->count());

        // Berkas tidak valid.
        $this->kirimGambar("{$uri}/icon", null, $token)->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimGambar("{$uri}/icon", UploadedFile::fake()->create('x.pdf', 100, 'application/pdf'), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimGambar("{$uri}/icon", UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml'), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimGambar("{$uri}/icon", UploadedFile::fake()->image('besar.jpg', 200, 200)->size(1500), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);       // ikon maksimal 1 MB
        $this->kirimGambar("{$uri}/image", UploadedFile::fake()->image('besar.jpg', 200, 200)->size(2500), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);       // gambar maksimal 2 MB
        $this->kirimGambar("{$uri}/image", UploadedFile::fake()->image('lebar.jpg', 4100, 100), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);

        $this->assertSame([], $disk->allFiles());

        // Jenis gambar tidak dikenal, dan kategori yang tidak ada.
        $this->kirimGambar("{$uri}/banner", UploadedFile::fake()->image('x.jpg', 100, 100), $token)->assertStatus(404);
        $this->kirimGambar('/api/v1/admin/categories/' . self::UUID_ASING . '/images/icon', UploadedFile::fake()->image('x.jpg', 100, 100), $token)
            ->assertStatus(404);
    }

    // -------------------------------------------------------------- BRAND: ADMIN

    public function test_admin_membuat_dan_mengubah_brand(): void
    {
        $token = $this->tokenAdmin();

        $res = $this->panggil('POST', '/api/v1/admin/brands', [
            'name'      => 'Nexora Wear',
            'id'        => 9,
            'logo_path' => 'x/y.jpg',   // harus diabaikan
        ], $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'data' => [
                'name'           => 'Nexora Wear',
                'slug'           => 'nexora-wear',
                'is_active'      => true,
                'logo_url'       => null,
                'products_count' => 0,
            ]]);

        $id = $res->json('data.id');
        $this->assertSame(36, strlen($id));
        $this->assertNull(Brand::first()->logo_path);

        // Nama kembar ditolak; nama berbeda dengan dasar slug yang sama mendapat akhiran.
        $this->panggil('POST', '/api/v1/admin/brands', ['name' => 'Nexora Wear'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $mirip = $this->panggil('POST', '/api/v1/admin/brands', ['name' => 'Nexora-Wear'], $token)->assertStatus(201);
        $this->assertStringStartsWith('nexora-wear-', $mirip->json('data.slug'));

        // Slug eksplisit dan kembar.
        $this->panggil('POST', '/api/v1/admin/brands', ['name' => 'Merek Baru', 'slug' => 'merek-baru'], $token)->assertStatus(201);
        $this->panggil('POST', '/api/v1/admin/brands', ['name' => 'Merek Lain', 'slug' => 'merek-baru'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);

        // Tidak valid.
        foreach ([[[], 'name'], [['name' => 'A'], 'name'], [['name' => 'Valid', 'slug' => 'Bad Slug!'], 'slug'], [['name' => 'Valid', 'is_active' => 'ya'], 'is_active']] as [$data, $kolom]) {
            $this->panggil('POST', '/api/v1/admin/brands', $data, $token)->assertStatus(422)->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame(3, Brand::count());
        $this->assertSame(3, ActivityLog::where('action', 'admin.brand_created')->count());

        // Ubah: nama berganti, slug tetap; nama sendiri boleh; nama milik brand lain ditolak.
        $this->panggil('PATCH', "/api/v1/admin/brands/{$id}", ['name' => 'Nexora Wear Baru'], $token)
            ->assertOk()->assertJson(['data' => ['name' => 'Nexora Wear Baru', 'slug' => 'nexora-wear']]);
        $this->panggil('PATCH', "/api/v1/admin/brands/{$id}", ['name' => 'Nexora Wear Baru'], $token)->assertOk();
        $this->panggil('PATCH', "/api/v1/admin/brands/{$id}", ['name' => 'Nexora-Wear'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->panggil('PATCH', "/api/v1/admin/brands/{$id}", ['slug' => 'merek-baru'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);

        // Dinonaktifkan: hilang dari daftar publik.
        $this->panggil('PATCH', "/api/v1/admin/brands/{$id}", ['is_active' => false], $token)
            ->assertOk()->assertJson(['data' => ['is_active' => false]]);

        $publik = collect($this->panggil('GET', '/api/v1/brands')->json('data'))->pluck('slug')->all();
        $this->assertNotContains('nexora-wear', $publik);

        $this->panggil('PATCH', '/api/v1/admin/brands/' . self::UUID_ASING, ['name' => 'Nama Valid'], $token)->assertStatus(404);
        $this->panggil('PATCH', '/api/v1/admin/brands/abc', ['name' => 'Nama Valid'], $token)->assertStatus(404);
    }

    public function test_brand_yang_dipakai_produk_tidak_bisa_dihapus_dan_logo_diproses(): void
    {
        $token = $this->tokenAdmin();
        $disk  = Storage::disk('public');

        $dipakai = $this->merek(['slug' => 'dipakai']);
        $this->produk($this->kategori(), $dipakai);

        $this->panggil('DELETE', "/api/v1/admin/brands/{$dipakai->uuid}", [], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['brand']);
        $this->assertNotNull(Brand::find($dipakai->id));

        // Brand tanpa produk bisa dihapus, berikut logonya.
        $kosong = $this->merek(['slug' => 'kosong']);
        $uri    = "/api/v1/admin/brands/{$kosong->uuid}/logo";

        $res = $this->kirimGambar($uri, UploadedFile::fake()->image('logo.png', 1000, 1000), $token)->assertOk();
        $this->assertNotNull($res->json('data.logo_url'));

        $logo1 = $kosong->fresh()->logo_path;
        $this->assertStringStartsWith('brands/logos/', $logo1);
        $info = getimagesizefromstring($disk->get($logo1));
        $this->assertSame([IMAGETYPE_JPEG, 512, 512], [$info[2], $info[0], $info[1]]);

        // Diganti: berkas lama terhapus.
        $this->kirimGambar($uri, UploadedFile::fake()->image('baru.jpg', 200, 200), $token)->assertOk();
        $logo2 = $kosong->fresh()->logo_path;
        $this->assertNotSame($logo1, $logo2);
        $disk->assertMissing($logo1);
        $disk->assertExists($logo2);

        // Dihapus.
        $this->panggil('DELETE', $uri, [], $token)->assertOk()->assertJson(['data' => ['logo_url' => null]]);
        $this->assertSame([], $disk->allFiles());

        // Berkas tidak valid.
        $this->kirimGambar($uri, null, $token)->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimGambar($uri, UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimGambar($uri, UploadedFile::fake()->image('besar.jpg', 200, 200)->size(1500), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->assertSame([], $disk->allFiles());

        // Logo ikut terhapus saat brand dihapus.
        $this->kirimGambar($uri, UploadedFile::fake()->image('logo.jpg', 300, 300), $token)->assertOk();
        $this->assertCount(1, $disk->allFiles());

        $this->panggil('DELETE', "/api/v1/admin/brands/{$kosong->uuid}", [], $token)->assertOk()->assertJson(['success' => true]);
        $this->assertNull(Brand::find($kosong->id));
        $this->assertSame([], $disk->allFiles());

        $this->panggil('DELETE', "/api/v1/admin/brands/{$kosong->uuid}", [], $token)->assertStatus(404);
        $this->assertSame(1, ActivityLog::where('action', 'admin.brand_deleted')->count());
    }

    public function test_daftar_brand_admin_menyertakan_nonaktif_dengan_filter_dan_jumlah_produk(): void
    {
        $token = $this->tokenAdmin();

        $alpha = $this->merek(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->merek(['name' => 'Beta', 'slug' => 'beta', 'is_active' => false]);
        $this->merek(['name' => 'Gamma', 'slug' => 'gamma']);
        $this->produk($this->kategori(), $alpha);

        $nama = fn($res) => collect($res->json('data'))->pluck('name')->all();

        $semua = $this->panggil('GET', '/api/v1/admin/brands', [], $token)->assertOk();
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $nama($semua));
        $this->assertSame(1, $semua->json('data.0.products_count'));
        $this->assertSame(0, $semua->json('data.1.products_count'));
        $this->assertFalse($semua->json('data.1.is_active'));
        $this->assertSame(
            ['current_page' => 1, 'per_page' => 20, 'total' => 3, 'last_page' => 1],
            $semua->json('meta')
        );

        $this->assertSame(['Beta'], $nama($this->panggil('GET', '/api/v1/admin/brands?is_active=0', [], $token)));
        $this->assertSame(['Alpha', 'Gamma'], $nama($this->panggil('GET', '/api/v1/admin/brands?is_active=1', [], $token)));
        $this->assertSame(['Gamma'], $nama($this->panggil('GET', '/api/v1/admin/brands?q=amm', [], $token)));

        $halaman = $this->panggil('GET', '/api/v1/admin/brands?per_page=2&page=2', [], $token)->assertOk();
        $this->assertSame(['Gamma'], $nama($halaman));
        $this->assertSame(2, $halaman->json('meta.last_page'));

        $this->panggil('GET', '/api/v1/admin/brands?is_active=xyz', [], $token)->assertStatus(422)->assertJsonValidationErrors(['is_active']);
        $this->panggil('GET', '/api/v1/admin/brands?per_page=0', [], $token)->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }
}
