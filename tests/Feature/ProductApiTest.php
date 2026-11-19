<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menguji produk seller dan detail produk publik lewat HTTP sungguhan
 * (SQLite memori dan disk palsu, tidak menyentuh MySQL maupun file asli).
 */
class ProductApiTest extends TestCase
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

    // ----------------------------------------------------------------- BANTUAN

    private function panggil(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->json($method, $uri, $data);
    }

    private function kirimFoto(string $uri, ?UploadedFile $file, string $token)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->post(
            $uri,
            $file === null ? [] : ['image' => $file],
            ['Accept' => 'application/json']
        );
    }

    /** Membuat pengguna (dan tokonya). Mengembalikan [pengguna, token, toko]. */
    private function penjual(array $roles = ['customer', 'seller'], bool $denganToko = true): array
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

        $toko = null;

        if ($denganToko) {
            $toko = new Store([
                'name'        => "Toko {$this->urut}",
                'slug'        => "toko-{$this->urut}",
                'phone'       => '628123456789',
                'province'    => 'DI Yogyakarta',
                'city'        => 'Sleman',
                'district'    => 'Depok',
                'postal_code' => '55281',
                'street'      => 'Jl. Contoh No. 1',
            ]);
            $toko->owner_id = $user->id;
            $toko->save();
            $toko = $toko->fresh();
        }

        return [$user, $user->createToken('test', ['*'], now()->addDay())->plainTextToken, $toko];
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

    private function varian(array $override = []): array
    {
        return array_merge([
            'name'        => 'Merah / M',
            'sku'         => 'KAOS-M',
            'options'     => ['Warna' => 'Merah', 'Ukuran' => 'M'],
            'price'       => 100000,
            'stock'       => 10,
            'weight_gram' => 200,
        ], $override);
    }

    private function dataProduk(Category $kategori, array $override = []): array
    {
        return array_merge([
            'name'        => 'Kaos Polos Premium',
            'description' => 'Bahan katun combed 30s',
            'category_id' => $kategori->uuid,
            'variants'    => [
                $this->varian(),
                [
                    'name'           => 'Biru / L',
                    'sku'            => 'KAOS-L',
                    'options'        => ['Warna' => 'Biru', 'Ukuran' => 'L'],
                    'price'          => 120000,
                    'discount_price' => 99000,
                    'stock'          => 5,
                    'weight_gram'    => 220,
                    'length_cm'      => 30,
                    'width_cm'       => 25,
                    'height_cm'      => 3,
                ],
            ],
        ], $override);
    }

    /** Membuat produk lewat API dan mengembalikan isi data produk. */
    private function buatProduk(string $token, Category $kategori, array $override = []): array
    {
        $res = $this->panggil('POST', '/api/v1/seller/products', $this->dataProduk($kategori, $override), $token);
        $res->assertStatus(201);

        return $res->json('data');
    }

    private function tambahFoto(string $token, string $idProduk, int $lebar = 800, int $tinggi = 600)
    {
        return $this->kirimFoto(
            "/api/v1/seller/products/{$idProduk}/images",
            UploadedFile::fake()->image('p.jpg', $lebar, $tinggi),
            $token
        );
    }

    /** Menambah satu foto lalu menayangkan produk. */
    private function tayangkan(string $token, array $produk): void
    {
        $this->tambahFoto($token, $produk['id'])->assertStatus(201);
        $this->panggil('POST', "/api/v1/seller/products/{$produk['id']}/publish", [], $token)->assertOk();
    }

    /** Membuat satu pesanan yang memuat varian ini (agar varian dianggap "pernah dibeli"). */
    private function pesan(Product $produk, ProductVariant $varian): void
    {
        $this->urut++;

        $pembeli = User::create([
            'name'     => "Pembeli {$this->urut}",
            'email'    => "pembeli{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);

        $grup = CheckoutGroup::create([
            'user_id'     => $pembeli->id,
            'number'      => CheckoutGroup::generateNumber(),
            'subtotal'    => 100000,
            'grand_total' => 100000,
        ]);

        $pesanan = Order::create([
            'checkout_group_id' => $grup->id,
            'user_id'           => $pembeli->id,
            'store_id'          => $produk->store_id,
            'number'            => Order::generateNumber($grup, 1),
            'shipping_address'  => ['city' => 'Sleman'],
            'subtotal'          => 100000,
            'grand_total'       => 100000,
        ]);

        $pesanan->items()->create([
            'product_id'   => $produk->id,
            'variant_id'   => $varian->id,
            'product_name' => $produk->name,
            'variant_name' => $varian->name,
            'price'        => 100000,
            'unit_price'   => 100000,
            'quantity'     => 1,
            'subtotal'     => 100000,
            'weight_gram'  => 200,
        ]);
    }

    // -------------------------------------------------------------------- AKSES

    public function test_endpoint_produk_seller_hanya_untuk_seller_dan_toko_ditangguhkan_hanya_bisa_dilihat(): void
    {
        $this->panggil('GET', '/api/v1/seller/products')->assertStatus(401);

        [, $tokenPembeli] = $this->penjual(['customer'], false);
        $this->panggil('GET', '/api/v1/seller/products', [], $tokenPembeli)->assertStatus(403);
        $this->panggil('POST', '/api/v1/seller/products', [], $tokenPembeli)->assertStatus(403);

        [, $tokenTanpaToko] = $this->penjual(['customer', 'seller'], false);
        $this->panggil('GET', '/api/v1/seller/products', [], $tokenTanpaToko)
            ->assertStatus(404)
            ->assertJson(['message' => 'Toko Anda tidak ditemukan.']);

        [, $token, $toko] = $this->penjual();
        $kategori = $this->kategori();
        $produk   = $this->buatProduk($token, $kategori);
        $id       = $produk['id'];
        $varianId = $produk['variants'][0]['id'];

        $toko->forceFill(['status' => 'suspended'])->save();

        // Toko ditangguhkan: boleh melihat.
        $this->panggil('GET', '/api/v1/seller/products', [], $token)->assertOk();
        $this->panggil('GET', "/api/v1/seller/products/{$id}", [], $token)->assertOk();

        // Tetapi tidak boleh mengubah apa pun.
        $perubahan = [
            ['POST', '/api/v1/seller/products', $this->dataProduk($kategori)],
            ['PATCH', "/api/v1/seller/products/{$id}", ['name' => 'Nama Baru Valid']],
            ['DELETE', "/api/v1/seller/products/{$id}", []],
            ['POST', "/api/v1/seller/products/{$id}/publish", []],
            ['POST', "/api/v1/seller/products/{$id}/unpublish", []],
            ['POST', "/api/v1/seller/products/{$id}/variants", $this->varian(['name' => 'Baru', 'sku' => 'BARU'])],
            ['PATCH', "/api/v1/seller/products/{$id}/variants/{$varianId}", ['price' => 150000]],
            ['DELETE', "/api/v1/seller/products/{$id}/variants/{$varianId}", []],
            ['POST', "/api/v1/seller/products/{$id}/variants/{$varianId}/stock", ['delta' => 1]],
        ];

        foreach ($perubahan as [$method, $uri, $data]) {
            $this->panggil($method, $uri, $data, $token)->assertStatus(403)->assertJson(['success' => false]);
        }

        $this->tambahFoto($token, $id)->assertStatus(403);

        $this->assertSame(1, Product::count());
        $this->assertSame('Kaos Polos Premium', Product::first()->name);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // --------------------------------------------------------------- PEMBUATAN

    public function test_membuat_produk_draf_dengan_varian_stok_awal_dan_input_sensitif_diabaikan(): void
    {
        [$user, $token, $toko] = $this->penjual();
        $kategori = $this->kategori(['name' => 'Fashion', 'slug' => 'fashion']);

        $data = $this->dataProduk($kategori, [
            // Semua di bawah ini harus diabaikan:
            'status'      => 'active',
            'store_id'    => 999,
            'sold_count'  => 500,
            'is_featured' => true,
            'slug'        => 'slug-curian',
            'price_min'   => 1,
            'id'          => 5,
            'uuid'        => self::UUID_ASING,
        ]);

        $res = $this->panggil('POST', '/api/v1/seller/products', $data, $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'data' => [
                'name'        => 'Kaos Polos Premium',
                'slug'        => 'kaos-polos-premium',
                'description' => 'Bahan katun combed 30s',
                'status'      => 'draft',
                'is_featured' => false,
                'sold_count'  => 0,
                'price_min'   => 99000,
                'price_max'   => 100000,
                'total_stock' => 15,
                'category'    => ['slug' => 'fashion'],
                'brand'       => null,
                'images'      => [],
            ]]);

        $this->assertSame(36, strlen($res->json('data.id')));
        $this->assertNotSame(self::UUID_ASING, $res->json('data.id'));
        $res->assertJsonMissingPath('data.store_id');
        $res->assertJsonMissingPath('data.deleted_at');
        $this->assertCount(2, $res->json('data.variants'));

        $v0 = $res->json('data.variants.0');
        $v1 = $res->json('data.variants.1');

        $this->assertSame(['Merah / M', 'KAOS-M', 10, 100000, 100000], [$v0['name'], $v0['sku'], $v0['stock'], $v0['price'], $v0['effective_price']]);
        $this->assertSame(['Warna' => 'Merah', 'Ukuran' => 'M'], $v0['options']);
        $this->assertSame([99000, 99000, 30, 25, 3], [$v1['discount_price'], $v1['effective_price'], $v1['length_cm'], $v1['width_cm'], $v1['height_cm']]);

        $produk = Product::first();

        $this->assertSame($toko->id, $produk->store_id);
        $this->assertSame('draft', $produk->status);
        $this->assertFalse($produk->is_featured);
        $this->assertSame(0, $produk->sold_count);
        $this->assertSame(99000, $produk->price_min);
        $this->assertSame('kaos-polos-premium', $produk->slug);

        // Stok awal tercatat di riwayat stok.
        $this->assertSame(2, StockMovement::count());
        $pertama = StockMovement::orderBy('id')->first();
        $this->assertSame([10, 10, 'initial', $user->id], [$pertama->quantity_change, $pertama->stock_after, $pertama->reason, $pertama->user_id]);

        $this->assertSame(1, ActivityLog::where('action', 'product.created')->where('user_id', $user->id)->count());
    }

    public function test_pembuatan_produk_menolak_data_tidak_valid(): void
    {
        [, $token] = $this->penjual();
        $kategori = $this->kategori();
        $nonaktif = $this->kategori(['is_active' => false]);
        $brandMati = $this->merek(['is_active' => false]);

        $this->panggil('POST', '/api/v1/seller/products', [], $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'category_id', 'variants']);

        $v0 = fn(array $ubah) => ['variants' => [
            array_merge($this->varian(), $ubah),
            $this->varian(['name' => 'Varian Dua', 'sku' => 'DUA']),
        ]];

        $tigaPuluhSatu = array_map(
            fn($n) => $this->varian(['name' => "Varian {$n}", 'sku' => "SKU-{$n}"]),
            range(1, 31)
        );

        $kasus = [
            'nama pendek'               => [['name' => 'AB'], 'name'],
            'kategori tidak ada'        => [['category_id' => self::UUID_ASING], 'category_id'],
            'kategori nonaktif'         => [['category_id' => $nonaktif->uuid], 'category_id'],
            'kategori bukan uuid'       => [['category_id' => 'abc'], 'category_id'],
            'brand nonaktif'            => [['brand_id' => $brandMati->uuid], 'brand_id'],
            'brand tidak ada'           => [['brand_id' => self::UUID_ASING], 'brand_id'],
            'tanpa varian'              => [['variants' => []], 'variants'],
            '31 varian'                 => [['variants' => $tigaPuluhSatu], 'variants'],
            'harga di bawah minimum'    => [$v0(['price' => 50]), 'variants.0.price'],
            'harga negatif'             => [$v0(['price' => -1]), 'variants.0.price'],
            'harga bukan angka'         => [$v0(['price' => 'abc']), 'variants.0.price'],
            'harga terlalu besar'       => [$v0(['price' => 2000000000]), 'variants.0.price'],
            'berat nol'                 => [$v0(['weight_gram' => 0]), 'variants.0.weight_gram'],
            'stok negatif'              => [$v0(['stock' => -1]), 'variants.0.stock'],
            'stok berlebih'             => [$v0(['stock' => 2000000]), 'variants.0.stock'],
            'diskon sama dengan harga'  => [$v0(['discount_price' => 100000]), 'variants.0.discount_price'],
            'masa diskon tanpa diskon'  => [$v0(['discount_starts_at' => '2026-12-01T00:00:00Z']), 'variants.0.discount_starts_at'],
            'akhir diskon sebelum awal' => [$v0([
                'discount_price' => 50000,
                'discount_starts_at' => '2026-12-10T00:00:00Z',
                'discount_ends_at' => '2026-12-01T00:00:00Z',
            ]), 'variants.0.discount_ends_at'],
            'dimensi sebagian'          => [$v0(['length_cm' => 10]), 'variants.0.length_cm'],
            'opsi berupa daftar'        => [$v0(['options' => ['Merah', 'M']]), 'variants.0.options'],
            'opsi terlalu banyak'       => [$v0(['options' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6']]), 'variants.0.options'],
            'nama varian kembar'        => [['variants' => [$this->varian(['sku' => 'A']), $this->varian(['name' => 'merah / m', 'sku' => 'B'])]], 'variants.1.name'],
            'sku kembar'                => [['variants' => [$this->varian(['sku' => 'SAMA-1']), $this->varian(['name' => 'Lain', 'sku' => 'sama-1'])]], 'variants.1.sku'],
            'sku karakter ilegal'       => [$v0(['sku' => 'sku bagus!']), 'variants.0.sku'],
        ];

        foreach ($kasus as $label => [$override, $kunci]) {
            try {
                $this->panggil('POST', '/api/v1/seller/products', $this->dataProduk($kategori, $override), $token)
                    ->assertStatus(422)
                    ->assertJson(['success' => false])
                    ->assertJsonValidationErrors([$kunci]);
            } catch (\Throwable $e) {
                $this->fail("Kasus '{$label}' (kunci {$kunci}): " . $e->getMessage());
            }
        }

        $this->assertSame(0, Product::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_slug_produk_dibuat_unik_dan_slug_produk_terhapus_tetap_terkunci(): void
    {
        [, $token] = $this->penjual();
        $kategori  = $this->kategori();

        $a = $this->buatProduk($token, $kategori);
        $this->assertSame('kaos-polos-premium', $a['slug']);

        $b = $this->buatProduk($token, $kategori);
        $this->assertNotSame($a['slug'], $b['slug']);
        $this->assertStringStartsWith('kaos-polos-premium-', $b['slug']);

        $this->panggil('DELETE', "/api/v1/seller/products/{$a['id']}", [], $token)->assertOk();

        $c = $this->buatProduk($token, $kategori);
        $this->assertNotSame($a['slug'], $c['slug']);
        $this->assertNotSame($b['slug'], $c['slug']);

        $this->assertSame(3, Product::withTrashed()->count());
    }

    // ------------------------------------------------------------------- UBAH

    public function test_mengubah_produk_sebagian_slug_tetap_dan_kolom_internal_tidak_berubah(): void
    {
        [$user, $token, $toko] = $this->penjual();
        $kat1      = $this->kategori(['slug' => 'fashion']);
        $kat2      = $this->kategori(['slug' => 'olahraga']);
        $katMati   = $this->kategori(['is_active' => false]);
        $brand     = $this->merek(['slug' => 'nexora-wear']);
        $brandMati = $this->merek(['is_active' => false]);

        $produk = $this->buatProduk($token, $kat1);
        $url    = "/api/v1/seller/products/{$produk['id']}";

        $this->panggil('PATCH', $url, [
            'name'        => 'Kaos Polos Premium V2',
            'brand_id'    => $brand->uuid,
            'category_id' => $kat2->uuid,
            'description' => null,
            // Semua di bawah ini harus diabaikan:
            'status'      => 'active',
            'store_id'    => 999,
            'slug'        => 'slug-curian',
            'is_featured' => true,
            'sold_count'  => 77,
        ], $token)
            ->assertOk()
            ->assertJson(['data' => [
                'name'        => 'Kaos Polos Premium V2',
                'slug'        => 'kaos-polos-premium',
                'status'      => 'draft',
                'is_featured' => false,
                'description' => null,
                'category'    => ['slug' => 'olahraga'],
                'brand'       => ['slug' => 'nexora-wear'],
            ]]);

        $baru = Product::first();
        $this->assertSame($toko->id, $baru->store_id);
        $this->assertSame(0, $baru->sold_count);
        $this->assertFalse($baru->is_featured);
        $this->assertSame('kaos-polos-premium', $baru->slug);

        // Brand dikosongkan.
        $this->panggil('PATCH', $url, ['brand_id' => null], $token)->assertOk()->assertJson(['data' => ['brand' => null]]);
        $this->assertNull(Product::first()->brand_id);

        // Isi kosong: tidak ada yang berubah.
        $this->panggil('PATCH', $url, [], $token)->assertOk()->assertJson(['data' => ['name' => 'Kaos Polos Premium V2']]);

        // Tidak valid.
        $this->panggil('PATCH', $url, ['name' => 'AB'], $token)->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->panggil('PATCH', $url, ['category_id' => null], $token)->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->panggil('PATCH', $url, ['category_id' => $katMati->uuid], $token)->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->panggil('PATCH', $url, ['category_id' => self::UUID_ASING], $token)->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->panggil('PATCH', $url, ['brand_id' => $brandMati->uuid], $token)->assertStatus(422)->assertJsonValidationErrors(['brand_id']);

        $this->assertSame('Kaos Polos Premium V2', Product::first()->name);
        $this->assertSame(3,
            ActivityLog::where('action', 'product.updated')->where('user_id', $user->id)->count()
        );
    }

    public function test_seller_tidak_bisa_menyentuh_produk_seller_lain(): void
    {
        [, $tokenA] = $this->penjual();
        [, $tokenB] = $this->penjual();
        $kategori   = $this->kategori();

        $a = $this->buatProduk($tokenA, $kategori);
        $b = $this->buatProduk($tokenB, $kategori);

        $urlA   = "/api/v1/seller/products/{$a['id']}";
        $urlB   = "/api/v1/seller/products/{$b['id']}";
        $varA   = $a['variants'][0]['id'];

        $serangan = [
            ['GET', $urlA, []],
            ['PATCH', $urlA, ['name' => 'Dibajak Orang']],
            ['DELETE', $urlA, []],
            ['POST', "{$urlA}/publish", []],
            ['POST', "{$urlA}/unpublish", []],
            ['DELETE', "{$urlA}/images/" . self::UUID_ASING, []],
            ['POST', "{$urlA}/variants", $this->varian(['name' => 'Siluman', 'sku' => 'SILUMAN'])],
            ['PATCH', "{$urlA}/variants/{$varA}", ['price' => 1000]],
            ['DELETE', "{$urlA}/variants/{$varA}", []],
            ['POST', "{$urlA}/variants/{$varA}/stock", ['delta' => 1]],
        ];

        foreach ($serangan as [$method, $uri, $data]) {
            $this->panggil($method, $uri, $data, $tokenB)->assertStatus(404)->assertJson(['success' => false]);
        }

        $this->tambahFoto($tokenB, $a['id'])->assertStatus(404);

        // Varian milik A diakses lewat produk milik B: tetap 404.
        $this->panggil('PATCH', "{$urlB}/variants/{$varA}", ['price' => 1000], $tokenB)->assertStatus(404);
        $this->panggil('POST', "{$urlB}/variants/{$varA}/stock", ['delta' => 1], $tokenB)->assertStatus(404);

        // Daftar B hanya berisi miliknya.
        $daftar = $this->panggil('GET', '/api/v1/seller/products', [], $tokenB)->assertOk();
        $this->assertCount(1, $daftar->json('data'));
        $this->assertSame($b['id'], $daftar->json('data.0.id'));

        // Produk A tidak berubah sama sekali.
        $tetap = $this->panggil('GET', $urlA, [], $tokenA)->assertOk();
        $this->assertSame('Kaos Polos Premium', $tetap->json('data.name'));
        $this->assertSame(100000, $tetap->json('data.variants.0.price'));
        $this->assertSame(10, $tetap->json('data.variants.0.stock'));
        $this->assertCount(2, $tetap->json('data.variants'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ------------------------------------------------------------------- FOTO

    public function test_foto_produk_diproses_diurutkan_dan_dihapus(): void
    {
        [, $token] = $this->penjual();
        $kategori  = $this->kategori();
        $produk    = $this->buatProduk($token, $kategori);
        $id        = $produk['id'];
        $uri       = "/api/v1/seller/products/{$id}";
        $disk      = Storage::disk('public');

        // Foto PNG besar diperkecil menjadi JPEG dengan sisi terpanjang 1600.
        $r1 = $this->kirimFoto("{$uri}/images", UploadedFile::fake()->image('a.png', 3200, 1600), $token)
            ->assertStatus(201)->assertJson(['success' => true]);

        $this->assertCount(1, $r1->json('data.images'));
        $g1 = ProductImage::orderBy('id')->first();
        $this->assertSame($g1->uuid, $r1->json('data.images.0.id'));
        $this->assertSame(0, $r1->json('data.images.0.sort_order'));
        $this->assertNotNull($r1->json('data.images.0.url'));
        $this->assertStringStartsWith('products/', $g1->path);
        $this->assertStringEndsWith('.jpg', $g1->path);
        $info = getimagesizefromstring($disk->get($g1->path));
        $this->assertSame([IMAGETYPE_JPEG, 1600, 800], [$info[2], $info[0], $info[1]]);

        // Foto kecil tidak diperbesar dan mendapat urutan berikutnya.
        $r2 = $this->kirimFoto("{$uri}/images", UploadedFile::fake()->image('b.jpg', 300, 300), $token)->assertStatus(201);
        $g2 = ProductImage::orderByDesc('id')->first();

        $this->assertCount(2, $r2->json('data.images'));
        $this->assertSame($g2->uuid, $r2->json('data.images.1.id'));
        $this->assertSame(1, $r2->json('data.images.1.sort_order'));
        $info = getimagesizefromstring($disk->get($g2->path));
        $this->assertSame([300, 300], [$info[0], $info[1]]);

        // Hapus foto kedua: berkasnya ikut terhapus.
        $this->panggil('DELETE', "{$uri}/images/{$g2->uuid}", [], $token)
            ->assertOk()->assertJsonCount(1, 'data.images');
        $disk->assertMissing($g2->path);
        $disk->assertExists($g1->path);

        // Foto terakhir dari produk yang tayang tidak bisa dihapus.
        $this->panggil('POST', "{$uri}/publish", [], $token)->assertOk();
        $this->panggil('DELETE', "{$uri}/images/{$g1->uuid}", [], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $disk->assertExists($g1->path);

        // Setelah penayangan dihentikan, boleh.
        $this->panggil('POST', "{$uri}/unpublish", [], $token)->assertOk();
        $this->panggil('DELETE', "{$uri}/images/{$g1->uuid}", [], $token)
            ->assertOk()->assertJson(['data' => ['images' => []]]);
        $this->assertSame([], $disk->allFiles());

        // Foto yang tidak ada: 404.
        $this->panggil('DELETE', "{$uri}/images/" . self::UUID_ASING, [], $token)->assertStatus(404);

        // Berkas tidak valid.
        $this->kirimFoto("{$uri}/images", null, $token)->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimFoto("{$uri}/images", UploadedFile::fake()->create('x.pdf', 100, 'application/pdf'), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimFoto("{$uri}/images", UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml'), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimFoto("{$uri}/images", UploadedFile::fake()->image('besar.jpg', 200, 200)->size(6000), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimFoto("{$uri}/images", UploadedFile::fake()->image('lebar.jpg', 4100, 100), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);

        $this->assertSame([], $disk->allFiles());
    }

    public function test_foto_produk_dibatasi_sembilan(): void
    {
        [, $token] = $this->penjual();
        $produk    = $this->buatProduk($token, $this->kategori());

        for ($i = 1; $i <= 9; $i++) {
            $this->tambahFoto($token, $produk['id'], 100, 100)->assertStatus(201);
        }

        $this->tambahFoto($token, $produk['id'], 100, 100)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);

        $this->assertSame(9, ProductImage::count());
        $this->assertCount(9, Storage::disk('public')->allFiles());   // berkas percobaan ke-10 dibersihkan
    }

    // -------------------------------------------------------------- PENAYANGAN

    public function test_penayangan_produk_butuh_foto_varian_aktif_dan_kategori_aktif(): void
    {
        [, $token] = $this->penjual();
        $kategori  = $this->kategori();
        $produk    = $this->buatProduk($token, $kategori);
        $id        = $produk['id'];
        $publish   = "/api/v1/seller/products/{$id}/publish";
        $unpublish = "/api/v1/seller/products/{$id}/unpublish";

        // Tanpa foto.
        $this->panggil('POST', $publish, [], $token)->assertStatus(422)->assertJsonValidationErrors(['images']);
        $this->assertSame('draft', Product::first()->status);

        $this->tambahFoto($token, $id)->assertStatus(201);

        // Semua varian nonaktif.
        foreach ($produk['variants'] as $v) {
            $this->panggil('PATCH', "/api/v1/seller/products/{$id}/variants/{$v['id']}", ['is_active' => false], $token)->assertOk();
        }
        $this->panggil('POST', $publish, [], $token)->assertStatus(422)->assertJsonValidationErrors(['variants']);

        foreach ($produk['variants'] as $v) {
            $this->panggil('PATCH', "/api/v1/seller/products/{$id}/variants/{$v['id']}", ['is_active' => true], $token)->assertOk();
        }

        // Kategori nonaktif.
        $kategori->update(['is_active' => false]);
        $this->panggil('POST', $publish, [], $token)->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $kategori->update(['is_active' => true]);

        // Berhasil.
        $res = $this->panggil('POST', $publish, [], $token)->assertOk()->assertJson(['data' => ['status' => 'active']]);
        $this->assertNotNull($res->json('data.published_at'));
        $pertama = Product::first()->published_at->toIso8601String();

        // Menayangkan lagi: tidak melakukan apa pun dan tidak dicatat dua kali.
        $this->panggil('POST', $publish, [], $token)->assertOk()->assertJson(['data' => ['status' => 'active']]);
        $this->assertSame(1, ActivityLog::where('action', 'product.published')->count());

        // Menghentikan penayangan, dan idempoten.
        $this->panggil('POST', $unpublish, [], $token)->assertOk()->assertJson(['data' => ['status' => 'inactive']]);
        $this->panggil('POST', $unpublish, [], $token)->assertOk()->assertJson(['data' => ['status' => 'inactive']]);
        $this->assertSame(1, ActivityLog::where('action', 'product.unpublished')->count());

        // Ditayangkan ulang: waktu penayangan pertama tetap diingat.
        $this->travel(10)->minutes();

        try {
            $this->panggil('POST', $publish, [], $token)->assertOk()->assertJson(['data' => ['status' => 'active']]);
        } finally {
            $this->travelBack();
        }

        $this->assertSame($pertama, Product::first()->published_at->toIso8601String());

        // Ditangguhkan admin: seller tidak bisa menayangkan maupun menghentikan.
        Product::first()->forceFill(['status' => 'suspended'])->save();

        $this->panggil('POST', $publish, [], $token)->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->panggil('POST', $unpublish, [], $token)->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame('suspended', Product::first()->status);
    }

    // ------------------------------------------------------------ DETAIL PUBLIK

    public function test_detail_publik_hanya_untuk_produk_yang_tayang_dan_tanpa_data_internal(): void
    {
        [, $token, $toko] = $this->penjual();
        $kategori = $this->kategori(['slug' => 'fashion']);
        $brand    = $this->merek(['slug' => 'nexora-wear']);

        $produk = $this->buatProduk($token, $kategori, ['brand_id' => $brand->uuid]);
        $id     = $produk['id'];
        $slug   = $produk['slug'];
        $v0     = $produk['variants'][0]['id'];
        $v1     = $produk['variants'][1]['id'];
        $url    = "/api/v1/products/{$slug}";

        $this->tambahFoto($token, $id)->assertStatus(201);

        // Draf: tidak tampil.
        $this->panggil('GET', $url)->assertStatus(404)->assertJson(['success' => false]);

        $this->panggil('POST', "/api/v1/seller/products/{$id}/publish", [], $token)->assertOk();

        // Varian kedua dinonaktifkan; varian pertama mendapat diskon yang sedang berjalan.
        $this->panggil('PATCH', "/api/v1/seller/products/{$id}/variants/{$v1}", ['is_active' => false], $token)->assertOk();
        $this->panggil('PATCH', "/api/v1/seller/products/{$id}/variants/{$v0}", [
            'discount_price'     => 90000,
            'discount_starts_at' => now()->subDay()->toIso8601String(),
            'discount_ends_at'   => now()->addDay()->toIso8601String(),
        ], $token)->assertOk();

        $res = $this->panggil('GET', $url)
            ->assertOk()
            ->assertJson(['success' => true, 'data' => [
                'id'        => $id,
                'name'      => 'Kaos Polos Premium',
                'slug'      => $slug,
                'category'  => ['slug' => 'fashion'],
                'brand'     => ['slug' => 'nexora-wear'],
                'price_min' => 90000,
                'price_max' => 90000,
                'store'     => ['name' => $toko->name, 'slug' => $toko->slug, 'city' => 'Sleman'],
            ]]);

        $this->assertCount(1, $res->json('data.images'));
        $this->assertNotNull($res->json('data.images.0.url'));

        // Hanya varian aktif yang tampil, dengan harga akhir setelah diskon.
        $this->assertCount(1, $res->json('data.variants'));
        $varian = $res->json('data.variants.0');
        $this->assertSame([$v0, 100000, 90000, true, 10], [$varian['id'], $varian['price'], $varian['final_price'], $varian['discount_active'], $varian['stock']]);
        $this->assertSame(['Warna' => 'Merah', 'Ukuran' => 'M'], $varian['options']);

        foreach (['status', 'is_featured', 'store_id', 'deleted_at', 'store.phone', 'store.owner_id', 'store.street', 'variants.0.sku', 'variants.0.is_active'] as $jalur) {
            $res->assertJsonMissingPath("data.{$jalur}");
        }

        // Masa diskon yang belum dimulai: harga akhir sama dengan harga normal.
        $this->panggil('PATCH', "/api/v1/seller/products/{$id}/variants/{$v0}", [
            'discount_starts_at' => now()->addDay()->toIso8601String(),
            'discount_ends_at'   => now()->addDays(2)->toIso8601String(),
        ], $token)->assertOk();

        $res = $this->panggil('GET', $url)->assertOk();
        $this->assertSame([100000, 100000, false, null], [
            $res->json('data.variants.0.price'),
            $res->json('data.variants.0.final_price'),
            $res->json('data.variants.0.discount_active'),
            $res->json('data.variants.0.discount_ends_at'),
        ]);
        $this->assertSame(100000, $res->json('data.price_min'));

        // Dinonaktifkan seller.
        $this->panggil('POST', "/api/v1/seller/products/{$id}/unpublish", [], $token)->assertOk();
        $this->panggil('GET', $url)->assertStatus(404);
        $this->panggil('POST', "/api/v1/seller/products/{$id}/publish", [], $token)->assertOk();
        $this->panggil('GET', $url)->assertOk();

        // Toko ditangguhkan.
        $toko->forceFill(['status' => 'suspended'])->save();
        $this->panggil('GET', $url)->assertStatus(404);
        $toko->forceFill(['status' => 'active'])->save();
        $this->panggil('GET', $url)->assertOk();

        // Kategori nonaktif.
        $kategori->update(['is_active' => false]);
        $this->panggil('GET', $url)->assertStatus(404);
        $kategori->update(['is_active' => true]);
        $this->panggil('GET', $url)->assertOk();

        // Brand nonaktif: produk tetap tampil, brand disembunyikan.
        $brand->update(['is_active' => false]);
        $this->panggil('GET', $url)->assertOk()->assertJson(['data' => ['brand' => null]]);
        $brand->update(['is_active' => true]);

        // Ditangguhkan admin.
        Product::first()->forceFill(['status' => 'suspended'])->save();
        $this->panggil('GET', $url)->assertStatus(404);
        Product::first()->forceFill(['status' => 'active'])->save();
        $this->panggil('GET', $url)->assertOk();

        // Slug tidak ada atau bentuknya tidak valid.
        $this->panggil('GET', '/api/v1/products/tidak-ada')->assertStatus(404);
        $this->panggil('GET', '/api/v1/products/Slug-Huruf-Besar')->assertStatus(404);

        // Dihapus seller.
        $this->panggil('DELETE', "/api/v1/seller/products/{$id}", [], $token)->assertOk();
        $this->panggil('GET', $url)->assertStatus(404);
    }

    // ------------------------------------------------------------------ VARIAN

    public function test_menambah_varian_menghitung_ulang_harga_mencatat_stok_awal_dan_menjaga_keunikan(): void
    {
        [, $token] = $this->penjual();
        $kategori  = $this->kategori();
        $produk    = $this->buatProduk($token, $kategori);
        $id        = $produk['id'];
        $url       = "/api/v1/seller/products/{$id}/variants";

        $res = $this->panggil('POST', $url, $this->varian([
            'name' => 'Hijau / XL',
            'sku' => 'KAOS-XL',
            'price' => 90000,
            'stock' => 7,
            'weight_gram' => 250,
        ]), $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'data' => ['price_min' => 90000, 'price_max' => 100000]]);

        $this->assertCount(3, $res->json('data.variants'));
        $this->assertSame(['Hijau / XL', 'KAOS-XL', 7], [
            $res->json('data.variants.2.name'),
            $res->json('data.variants.2.sku'),
            $res->json('data.variants.2.stock'),
        ]);

        $this->assertSame(3, StockMovement::count());
        $this->assertSame([7, 7, 'initial'], [
            StockMovement::orderByDesc('id')->first()->quantity_change,
            StockMovement::orderByDesc('id')->first()->stock_after,
            StockMovement::orderByDesc('id')->first()->reason,
        ]);
        $this->assertSame(1, ActivityLog::where('action', 'product.variant_added')->count());

        // Nama dan SKU kembar (tanpa membedakan huruf besar kecil).
        $this->panggil('POST', $url, $this->varian(['name' => 'hijau / xl', 'sku' => 'BARU-1']), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->panggil('POST', $url, $this->varian(['name' => 'Baru Lagi', 'sku' => 'kaos-xl']), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['sku']);

        // Validasi nilai.
        $this->panggil('POST', $url, $this->varian(['name' => 'X1', 'sku' => 'X1', 'price' => 50]), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['price']);
        $this->panggil('POST', $url, $this->varian(['name' => 'X2', 'sku' => 'X2', 'discount_price' => 100000]), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['discount_price']);
        $this->panggil('POST', $url, $this->varian(['name' => 'X3', 'sku' => 'X3', 'width_cm' => 10]), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['length_cm']);
        $this->panggil('POST', $url, $this->varian(['name' => 'X4', 'sku' => 'X4', 'stock' => -1]), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['stock']);
        $this->panggil('POST', $url, [], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['price', 'weight_gram']);

        // Tanpa nama: dinamai "Default"; yang kedua kembar.
        $this->panggil('POST', $url, ['price' => 100000, 'weight_gram' => 100], $token)
            ->assertStatus(201)->assertJsonPath('data.variants.3.name', 'Default');
        $this->panggil('POST', $url, ['price' => 100000, 'weight_gram' => 100], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->assertSame(4, ProductVariant::count());
    }

    public function test_varian_dibatasi_tiga_puluh_per_produk(): void
    {
        [, $token] = $this->penjual();

        $tigaPuluh = array_map(
            fn($n) => $this->varian(['name' => "V{$n}", 'sku' => "S{$n}", 'stock' => 0]),
            range(1, 30)
        );

        $produk = $this->buatProduk($token, $this->kategori(), ['variants' => $tigaPuluh]);

        $this->panggil('POST', "/api/v1/seller/products/{$produk['id']}/variants", $this->varian(['name' => 'V31', 'sku' => 'S31']), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['variants']);

        $this->assertSame(30, ProductVariant::count());
        $this->assertSame(0, StockMovement::count());   // stok 0 tidak membuat riwayat
    }

    public function test_mengubah_varian_sebagian_stok_diabaikan_dan_nilai_akhir_diperiksa(): void
    {
        [, $token] = $this->penjual();
        $produk    = $this->buatProduk($token, $this->kategori());
        $id        = $produk['id'];
        $v0        = $produk['variants'][0]['id'];
        $v1        = $produk['variants'][1]['id'];
        $u0        = "/api/v1/seller/products/{$id}/variants/{$v0}";
        $u1        = "/api/v1/seller/products/{$id}/variants/{$v1}";

        $res = $this->panggil('PATCH', $u0, [
            'price'              => 110000,
            'discount_price'     => 100000,
            'discount_starts_at' => now()->subDay()->toIso8601String(),
            'discount_ends_at'   => now()->addYear()->toIso8601String(),
            'weight_gram'        => 300,
            'length_cm'          => 20,
            'width_cm'           => 15,
            'height_cm'          => 2,
            'name'               => 'Merah / M',   // nama dan SKU sendiri boleh dikirim ulang
            'sku'                => 'KAOS-M',
            'stock'              => 999,           // harus diabaikan
            'id'                 => 5,
        ], $token)->assertOk();

        $varian = $res->json('data.variants.0');
        $this->assertSame([110000, 100000, 100000, 300, 10], [
            $varian['price'],
            $varian['discount_price'],
            $varian['effective_price'],
            $varian['weight_gram'],
            $varian['stock'],
        ]);
        $this->assertSame([20, 15, 2], [$varian['length_cm'], $varian['width_cm'], $varian['height_cm']]);
        $this->assertSame(99000, $res->json('data.price_min'));   // varian kedua: 99.000
        $this->assertSame(100000, $res->json('data.price_max'));

        // Menurunkan harga di bawah harga diskon yang masih ada: ditolak.
        $this->panggil('PATCH', $u0, ['price' => 90000], $token)->assertStatus(422)->assertJsonValidationErrors(['discount_price']);
        $this->assertSame(110000, ProductVariant::where('uuid', $v0)->value('price'));

        // Harga diskon dikosongkan: masa diskon ikut kosong.
        $res = $this->panggil('PATCH', $u0, ['discount_price' => null], $token)->assertOk();
        $this->assertNull($res->json('data.variants.0.discount_price'));
        $this->assertNull($res->json('data.variants.0.discount_starts_at'));
        $this->assertNull($res->json('data.variants.0.discount_ends_at'));
        $this->assertSame(110000, $res->json('data.variants.0.effective_price'));

        // Validasi.
        $this->panggil('PATCH', $u0, ['price' => 50], $token)->assertStatus(422)->assertJsonValidationErrors(['price']);
        $this->panggil('PATCH', $u0, ['discount_price' => 110000], $token)->assertStatus(422)->assertJsonValidationErrors(['discount_price']);
        $this->panggil('PATCH', $u0, ['name' => 'biru / l'], $token)->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->panggil('PATCH', $u0, ['sku' => 'kaos-l'], $token)->assertStatus(422)->assertJsonValidationErrors(['sku']);
        $this->panggil('PATCH', $u0, ['weight_gram' => 0], $token)->assertStatus(422)->assertJsonValidationErrors(['weight_gram']);

        // Dimensi: mengosongkan satu dari tiga ditolak; mengosongkan ketiganya boleh.
        $this->panggil('PATCH', $u1, ['length_cm' => null], $token)->assertStatus(422)->assertJsonValidationErrors(['length_cm']);
        $this->panggil('PATCH', $u1, ['length_cm' => null, 'width_cm' => null, 'height_cm' => null], $token)
            ->assertOk()->assertJsonPath('data.variants.1.length_cm', null);

        // Opsi diganti, lalu dikosongkan; SKU dikosongkan.
        $this->panggil('PATCH', $u0, ['options' => ['Warna' => 'Hitam']], $token)
            ->assertOk()->assertJsonPath('data.variants.0.options', ['Warna' => 'Hitam']);
        $this->panggil('PATCH', $u0, ['options' => [], 'sku' => null], $token)
            ->assertOk()->assertJsonPath('data.variants.0.options', null);
        $this->assertNull(ProductVariant::where('uuid', $v0)->value('sku'));

        // Isi kosong: tidak ada yang berubah. Varian tidak ada: 404.
        $this->panggil('PATCH', $u0, [], $token)->assertOk();
        $this->panggil('PATCH', "/api/v1/seller/products/{$id}/variants/" . self::UUID_ASING, ['price' => 100000], $token)->assertStatus(404);

        // Stok tetap 10 meskipun dikirim 999 tadi.
        $this->assertSame(10, ProductVariant::where('uuid', $v0)->value('stock'));
    }

    public function test_varian_aktif_terakhir_produk_tayang_dan_varian_yang_pernah_dibeli_tidak_bisa_dihapus(): void
    {
        [, $token] = $this->penjual();
        $kategori  = $this->kategori();

        // Produk dengan satu varian: varian itu tidak bisa dihapus.
        $tunggal = $this->buatProduk($token, $kategori, ['variants' => [$this->varian()]]);
        $this->panggil('DELETE', "/api/v1/seller/products/{$tunggal['id']}/variants/{$tunggal['variants'][0]['id']}", [], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['variant']);

        // Produk tayang dengan dua varian.
        $produk = $this->buatProduk($token, $kategori);
        $id     = $produk['id'];
        $v0     = $produk['variants'][0]['id'];
        $v1     = $produk['variants'][1]['id'];
        $this->tayangkan($token, $produk);

        // Nonaktifkan satu varian boleh; yang terakhir aktif tidak boleh.
        $this->panggil('PATCH', "/api/v1/seller/products/{$id}/variants/{$v1}", ['is_active' => false], $token)->assertOk();
        $this->panggil('PATCH', "/api/v1/seller/products/{$id}/variants/{$v0}", ['is_active' => false], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['is_active']);
        $this->assertTrue((bool) ProductVariant::where('uuid', $v0)->value('is_active'));

        // Menghapus varian aktif terakhir dari produk tayang: ditolak.
        $this->panggil('DELETE', "/api/v1/seller/products/{$id}/variants/{$v0}", [], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['variant']);

        // Menghapus varian nonaktif: boleh, dan harga dihitung ulang.
        $res = $this->panggil('DELETE', "/api/v1/seller/products/{$id}/variants/{$v1}", [], $token)->assertOk();
        $this->assertCount(1, $res->json('data.variants'));
        $this->assertSame(100000, $res->json('data.price_min'));
        $this->assertSame(1, ProductVariant::where('product_id', Product::first()->id)->count());

        // Varian yang pernah dibeli tidak bisa dihapus.
        $tambah = $this->panggil('POST', "/api/v1/seller/products/{$id}/variants", $this->varian(['name' => 'Laris', 'sku' => 'LARIS']), $token)
            ->assertStatus(201);
        $v2 = $tambah->json('data.variants.1.id');

        $this->pesan(Product::where('uuid', $id)->first(), ProductVariant::where('uuid', $v2)->first());

        $this->panggil('DELETE', "/api/v1/seller/products/{$id}/variants/{$v2}", [], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['variant']);

        // Tetapi boleh dinonaktifkan.
        $this->panggil('PATCH', "/api/v1/seller/products/{$id}/variants/{$v2}", ['is_active' => false], $token)->assertOk();

        $this->assertSame(1, ActivityLog::where('action', 'product.variant_removed')->count());
    }

    // -------------------------------------------------------------------- STOK

    public function test_stok_berubah_lewat_endpoint_stok_tidak_minus_tercatat_dan_dibatasi(): void
    {
        [$user, $token] = $this->penjual();
        $produk = $this->buatProduk($token, $this->kategori());
        $id     = $produk['id'];
        $v0     = $produk['variants'][0]['id'];
        $url    = "/api/v1/seller/products/{$id}/variants/{$v0}/stock";
        $vid    = ProductVariant::where('uuid', $v0)->value('id');

        $stok = fn() => (int) ProductVariant::where('uuid', $v0)->value('stock');

        // Tambah stok.
        $res = $this->panggil('POST', $url, ['delta' => 5, 'note' => 'Restock gudang'], $token)
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame(15, $res->json('data.variants.0.stock'));
        $this->assertSame(20, $res->json('data.total_stock'));   // 15 + 5 dari varian kedua

        $gerak = StockMovement::where('variant_id', $vid)->orderByDesc('id')->first();
        $this->assertSame([5, 15, 'manual', 'Restock gudang', $user->id], [
            $gerak->quantity_change,
            $gerak->stock_after,
            $gerak->reason,
            $gerak->note,
            $gerak->user_id,
        ]);

        // Kurangi stok.
        $this->panggil('POST', $url, ['delta' => -3], $token)->assertOk();
        $this->assertSame(12, $stok());

        $jumlahRiwayat = StockMovement::where('variant_id', $vid)->count();   // 1 stok awal + 2

        // Stok tidak cukup: ditolak dan tidak ada yang berubah.
        $this->panggil('POST', $url, ['delta' => -20], $token)->assertStatus(422)->assertJsonValidationErrors(['delta']);
        $this->assertSame(12, $stok());
        $this->assertSame($jumlahRiwayat, StockMovement::where('variant_id', $vid)->count());

        // Masukan tidak valid.
        foreach ([['delta' => 0], ['delta' => 'abc'], ['delta' => 1000001], ['delta' => -1000001], [], ['delta' => 1, 'note' => str_repeat('a', 256)]] as $salah) {
            $this->panggil('POST', $url, $salah, $token)->assertStatus(422);
        }
        $this->assertSame(12, $stok());

        // Batas atas stok 1.000.000.
        $this->panggil('POST', $url, ['delta' => 999000], $token)->assertOk();
        $this->assertSame(999012, $stok());
        $this->panggil('POST', $url, ['delta' => 1000], $token)->assertStatus(422)->assertJsonValidationErrors(['delta']);
        $this->assertSame(999012, $stok());

        // Dikosongkan sampai nol, lalu tidak bisa dikurangi lagi.
        $this->panggil('POST', $url, ['delta' => -999012], $token)->assertOk();
        $this->assertSame(0, $stok());
        $this->panggil('POST', $url, ['delta' => -1], $token)->assertStatus(422)->assertJsonValidationErrors(['delta']);

        $akhir = StockMovement::where('variant_id', $vid)->orderByDesc('id')->first();
        $this->assertSame([-999012, 0], [$akhir->quantity_change, $akhir->stock_after]);

        // 1 stok awal + 4 perubahan berhasil (+5, -3, +999000, -999012).
        $this->assertSame(5, StockMovement::where('variant_id', $vid)->count());
        $this->assertSame(4, ActivityLog::where('action', 'product.stock_adjusted')->count());

        // Varian tidak ada: 404.
        $this->panggil('POST', "/api/v1/seller/products/{$id}/variants/" . self::UUID_ASING . '/stock', ['delta' => 1], $token)->assertStatus(404);
    }

    // -------------------------------------------------------------------- HAPUS

    public function test_menghapus_produk_menyembunyikannya_di_mana_saja(): void
    {
        [$user, $token] = $this->penjual();
        $produk = $this->buatProduk($token, $this->kategori());
        $id     = $produk['id'];
        $this->tayangkan($token, $produk);

        $this->panggil('GET', "/api/v1/products/{$produk['slug']}")->assertOk();

        $this->panggil('DELETE', "/api/v1/seller/products/{$id}", [], $token)
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSoftDeleted('products', ['uuid' => $id]);
        $this->panggil('GET', "/api/v1/products/{$produk['slug']}")->assertStatus(404);
        $this->panggil('GET', "/api/v1/seller/products/{$id}", [], $token)->assertStatus(404);
        $this->panggil('GET', '/api/v1/seller/products', [], $token)->assertOk()->assertJson(['data' => []]);
        $this->panggil('DELETE', "/api/v1/seller/products/{$id}", [], $token)->assertStatus(404);

        $this->assertSame(1, ActivityLog::where('action', 'product.deleted')->where('user_id', $user->id)->count());
    }

    // ------------------------------------------------------------------- DAFTAR

    public function test_daftar_produk_seller_dengan_filter_pencarian_aman_dan_paginasi(): void
    {
        [, $token] = $this->penjual();
        [, $tokenLain] = $this->penjual();
        $kategori = $this->kategori();

        $alpha = $this->buatProduk($token, $kategori, ['name' => 'Alpha Satu']);
        $this->buatProduk($token, $kategori, ['name' => 'Beta Dua']);
        $this->buatProduk($token, $kategori, ['name' => '100% Katun']);
        $this->buatProduk($tokenLain, $kategori, ['name' => 'Milik Orang Lain']);

        $this->tayangkan($token, $alpha);

        $nama = fn($res) => collect($res->json('data'))->pluck('name')->all();

        // Terbaru dulu, hanya milik sendiri.
        $semua = $this->panggil('GET', '/api/v1/seller/products', [], $token)->assertOk();
        $this->assertSame(['100% Katun', 'Beta Dua', 'Alpha Satu'], $nama($semua));
        $this->assertSame(
            ['current_page' => 1, 'per_page' => 15, 'total' => 3, 'last_page' => 1],
            $semua->json('meta')
        );
        $semua->assertJsonStructure(['data' => ['*' => [
            'id',
            'name',
            'slug',
            'status',
            'images',
            'variants',
            'price_min',
            'price_max',
            'total_stock',
        ]]]);

        // Filter status dan pencarian.
        $this->assertSame(['Alpha Satu'], $nama($this->panggil('GET', '/api/v1/seller/products?status=active', [], $token)));
        $this->assertSame(['100% Katun', 'Beta Dua'], $nama($this->panggil('GET', '/api/v1/seller/products?status=draft', [], $token)));
        $this->assertSame(['Beta Dua'], $nama($this->panggil('GET', '/api/v1/seller/products?q=beta', [], $token)));

        // Tanda % dan _ dianggap huruf biasa.
        $this->assertSame(['100% Katun'], $nama($this->panggil('GET', '/api/v1/seller/products?q=' . urlencode('%'), [], $token)));
        $this->assertSame([], $nama($this->panggil('GET', '/api/v1/seller/products?q=' . urlencode('_'), [], $token)));

        // Paginasi.
        $halaman = $this->panggil('GET', '/api/v1/seller/products?per_page=2&page=2', [], $token)->assertOk();
        $this->assertSame(['Alpha Satu'], $nama($halaman));
        $this->assertSame(
            ['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2],
            $halaman->json('meta')
        );

        // Parameter tidak valid.
        $this->panggil('GET', '/api/v1/seller/products?status=xyz', [], $token)->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->panggil('GET', '/api/v1/seller/products?per_page=100', [], $token)->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->panggil('GET', '/api/v1/seller/products?per_page=0', [], $token)->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }
}
