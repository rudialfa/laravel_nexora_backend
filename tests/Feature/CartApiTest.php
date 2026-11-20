<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Menguji keranjang belanja lewat HTTP sungguhan (SQLite memori dan disk palsu).
 */
class CartApiTest extends TestCase
{
    use RefreshDatabase;

    private const UUID_ASING = '00000000-0000-4000-8000-000000000000';

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
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

    /** @return array{0: User, 1: string} pengguna dan token */
    private function pembeli(): array
    {
        $this->urut++;

        $user = User::create([
            'name'     => "Pembeli {$this->urut}",
            'email'    => "pembeli{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);

        return [$user, $user->createToken('test', ['*'], now()->addDay())->plainTextToken];
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

    private function tokenPemilik(Store $toko): string
    {
        return $toko->owner->createToken('test', ['*'], now()->addDay())->plainTextToken;
    }

    private function kategori(array $isi = []): Category
    {
        $this->urut++;

        return Category::create(array_merge([
            'name' => "Kategori {$this->urut}",
            'slug' => "kategori-{$this->urut}",
        ], $isi));
    }

    /**
     * Membuat produk (dan satu varian) langsung di database.
     * Opsi: produk, nama, opsi, harga, diskon, berat, stok, status, varian_aktif, gambar, dihapus.
     */
    private function varian(Store $toko, Category $kategori, array $o = []): ProductVariant
    {
        $this->urut++;

        $produk = new Product([
            'name'        => $o['produk'] ?? "Produk {$this->urut}",
            'slug'        => "produk-{$this->urut}",
            'category_id' => $kategori->id,
        ]);
        $produk->store_id = $toko->id;
        $produk->forceFill([
            'status'       => $o['status'] ?? Product::STATUS_ACTIVE,
            'published_at' => now(),
        ])->save();

        $varian = $produk->variants()->create([
            'name'           => $o['nama'] ?? 'Default',
            'options'        => $o['opsi'] ?? null,
            'price'          => $o['harga'] ?? 100000,
            'discount_price' => $o['diskon'] ?? null,
            'weight_gram'    => $o['berat'] ?? 200,
            'is_active'      => $o['varian_aktif'] ?? true,
        ]);

        $stok = $o['stok'] ?? 10;

        if ($stok > 0) {
            $varian->adjustStock($stok, 'initial');
        }

        if ($o['gambar'] ?? true) {
            $produk->images()->create(['path' => "products/foto-{$this->urut}.jpg", 'sort_order' => 0]);
        }

        if ($o['dihapus'] ?? false) {
            $produk->delete();
        }

        return $varian->fresh();
    }

    private function isi(User $user, ProductVariant $varian, int $jumlah = 1, bool $dipilih = true): CartItem
    {
        return CartItem::create([
            'user_id'     => $user->id,
            'variant_id'  => $varian->id,
            'quantity'    => $jumlah,
            'is_selected' => $dipilih,
        ]);
    }

    private function semuaRute(): array
    {
        $u = '/api/v1/cart';
        $v = self::UUID_ASING;

        return [
            ['GET', $u],
            ['DELETE', $u],
            ['POST', "{$u}/items"],
            ['PATCH', "{$u}/items/{$v}"],
            ['DELETE', "{$u}/items/{$v}"],
            ['POST', "{$u}/select"],
        ];
    }

    // -------------------------------------------------------------------- AKSES

    public function test_keranjang_butuh_login_dan_akun_aktif(): void
    {
        foreach ($this->semuaRute() as [$method, $uri]) {
            $this->panggil($method, $uri)->assertStatus(401);
        }

        [$user, $token] = $this->pembeli();
        $user->forceFill(['status' => 'suspended'])->save();

        foreach ($this->semuaRute() as [$method, $uri]) {
            $this->panggil($method, $uri, [], $token)->assertStatus(403);
        }
    }

    public function test_keranjang_kosong_memuat_struktur_lengkap(): void
    {
        [, $token] = $this->pembeli();

        $this->panggil('GET', '/api/v1/cart', [], $token)
            ->assertOk()
            ->assertJsonPath('data.groups', [])
            ->assertJson(['success' => true, 'data' => ['summary' => [
                'lines'             => 0,
                'quantity_total'    => 0,
                'selected_count'    => 0,
                'selected_quantity' => 0,
                'subtotal'          => 0,
                'total_weight_gram' => 0,
                'has_issues'        => false,
            ]]]);
    }

    // ------------------------------------------------------------------- TAMBAH

    public function test_menambah_item_menghitung_harga_terbaru_dan_menggabung_item_yang_sama(): void
    {
        $toko = $this->toko('active', ['name' => 'Toko Budi', 'slug' => 'toko-budi']);
        $v    = $this->varian($toko, $this->kategori(), [
            'nama' => 'Merah / M',
            'opsi' => ['Warna' => 'Merah'],
            'harga' => 100000,
            'diskon' => 80000,
            'stok' => 10,
        ]);
        [, $token] = $this->pembeli();

        $res = $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $v->uuid, 'quantity' => 2], $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Produk ditambahkan ke keranjang.']);

        $item = $res->json('data.groups.0.items.0');

        $this->assertSame($v->uuid, $item['variant_id']);
        $this->assertSame([100000, 80000, true, 2, 160000, 10], [
            $item['price'],
            $item['final_price'],
            $item['discount_active'],
            $item['quantity'],
            $item['line_total'],
            $item['stock'],
        ]);
        $this->assertSame(['available', null, true], [$item['status'], $item['reason'], $item['is_selected']]);
        $this->assertSame(['Merah / M', ['Warna' => 'Merah'], 200], [
            $item['variant']['name'],
            $item['variant']['options'],
            $item['variant']['weight_gram'],
        ]);
        $this->assertSame($v->product->slug, $item['product']['slug']);
        $this->assertStringContainsString('products/foto-', $item['product']['image_url']);

        $this->assertSame(
            ['lines' => 1, 'quantity_total' => 2, 'selected_count' => 1, 'selected_quantity' => 2, 'subtotal' => 160000, 'total_weight_gram' => 400, 'has_issues' => false],
            $res->json('data.summary')
        );
        $this->assertSame(['toko-budi', 1, 160000], [
            $res->json('data.groups.0.store.slug'),
            $res->json('data.groups.0.selected_count'),
            $res->json('data.groups.0.subtotal'),
        ]);

        // Tidak ada id numerik atau data internal yang bocor.
        $res->assertJsonMissingPath('data.groups.0.items.0.id');
        $this->assertStringNotContainsString('user_id', $res->getContent());
        $this->assertStringNotContainsString('628123456789', $res->getContent());

        // Item yang sama ditambah lagi (jumlah tidak dikirim = 1): digabung, bukan baris baru.
        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $v->uuid], $token)
            ->assertOk()
            ->assertJson(['message' => 'Jumlah produk di keranjang diperbarui.'])
            ->assertJsonPath('data.groups.0.items.0.quantity', 3);

        $this->assertSame(1, CartItem::count());
        $this->assertSame(3, CartItem::first()->quantity);
    }

    public function test_menambah_item_menolak_masukan_tidak_valid(): void
    {
        [, $token] = $this->pembeli();
        $v         = $this->varian($this->toko(), $this->kategori());

        $kasus = [
            [[], 'variant_id'],
            [['variant_id' => 'abc', 'quantity' => 1], 'variant_id'],
            [['variant_id' => self::UUID_ASING, 'quantity' => 1], 'variant_id'],      // varian tidak ada
            [['variant_id' => $v->uuid, 'quantity' => 0], 'quantity'],
            [['variant_id' => $v->uuid, 'quantity' => 100], 'quantity'],
            [['variant_id' => $v->uuid, 'quantity' => -1], 'quantity'],
            [['variant_id' => $v->uuid, 'quantity' => 'abc'], 'quantity'],
            [['variant_id' => $v->uuid, 'quantity' => 1.5], 'quantity'],
        ];

        foreach ($kasus as [$data, $kolom]) {
            $this->panggil('POST', '/api/v1/cart/items', $data, $token)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame(0, CartItem::count());
    }

    public function test_item_yang_tidak_layak_dibeli_ditolak_dengan_pesan_seragam(): void
    {
        $toko = $this->toko();
        $kat  = $this->kategori();
        [, $token] = $this->pembeli();

        $umum = [
            'varian nonaktif'     => $this->varian($toko, $kat, ['varian_aktif' => false]),
            'produk draf'         => $this->varian($toko, $kat, ['status' => 'draft']),
            'produk nonaktif'     => $this->varian($toko, $kat, ['status' => 'inactive']),
            'produk ditangguhkan' => $this->varian($toko, $kat, ['status' => 'suspended']),
            'produk dihapus'      => $this->varian($toko, $kat, ['dihapus' => true]),
            'toko diblokir'       => $this->varian($this->toko('suspended'), $kat),
            'kategori nonaktif'   => $this->varian($toko, $this->kategori(['is_active' => false])),
        ];

        $tokoHapus = $this->toko();
        $umum['toko dihapus'] = $this->varian($tokoHapus, $kat);
        $tokoHapus->delete();

        foreach ($umum as $label => $varian) {
            try {
                $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $varian->uuid, 'quantity' => 1], $token)
                    ->assertStatus(422)
                    ->assertJsonPath('errors.variant_id.0', CartService::PESAN_TIDAK_TERSEDIA);
            } catch (\Throwable $e) {
                $this->fail("Kasus '{$label}': " . $e->getMessage());
            }
        }

        // Stok habis: pesan tersendiri.
        $habis = $this->varian($toko, $kat, ['stok' => 0]);
        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $habis->uuid], $token)
            ->assertStatus(422)->assertJsonPath('errors.variant_id.0', CartService::PESAN_STOK_HABIS);

        // Produk milik toko sendiri.
        $tokoSaya = $this->toko();
        $milikku  = $this->varian($tokoSaya, $kat);
        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $milikku->uuid], $this->tokenPemilik($tokoSaya))
            ->assertStatus(422)->assertJsonPath('errors.variant_id.0', CartService::PESAN_TOKO_SENDIRI);

        $this->assertSame(0, CartItem::count());
    }

    public function test_tambah_item_dibatasi_stok_dan_maksimal_sembilan_puluh_sembilan(): void
    {
        $toko = $this->toko();
        $kat  = $this->kategori();
        [, $token] = $this->pembeli();

        $v = $this->varian($toko, $kat, ['stok' => 5]);

        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $v->uuid, 'quantity' => 3], $token)->assertStatus(201);

        // 3 + 3 melebihi stok 5: ditolak, jumlah tetap 3.
        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $v->uuid, 'quantity' => 3], $token)
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Stok tidak cukup. Stok tersedia: 5, sudah ada di keranjang: 3.');
        $this->assertSame(3, CartItem::first()->quantity);

        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $v->uuid, 'quantity' => 2], $token)
            ->assertOk()->assertJsonPath('data.groups.0.items.0.quantity', 5);
        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $v->uuid], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['quantity']);
        $this->assertSame(5, CartItem::first()->quantity);

        // Batas 99 per item, walau stok melimpah.
        $besar = $this->varian($toko, $kat, ['stok' => 500]);

        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $besar->uuid, 'quantity' => 99], $token)->assertStatus(201);
        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $besar->uuid, 'quantity' => 1], $token)
            ->assertStatus(422)->assertJsonPath('errors.quantity.0', 'Maksimal 99 per produk.');

        $this->assertSame(99, CartItem::where('variant_id', $besar->id)->value('quantity'));
    }

    // -------------------------------------------------------------------- UBAH

    public function test_mengubah_jumlah_dan_status_pilih_dengan_validasi_stok(): void
    {
        $toko = $this->toko();
        $kat  = $this->kategori();
        [$user, $token] = $this->pembeli();

        $v   = $this->varian($toko, $kat, ['stok' => 10, 'harga' => 100000]);
        $this->isi($user, $v, 2);
        $url = "/api/v1/cart/items/{$v->uuid}";

        $res = $this->panggil('PATCH', $url, ['quantity' => 5], $token)->assertOk()->assertJson(['success' => true]);
        $this->assertSame([5, 500000], [$res->json('data.groups.0.items.0.quantity'), $res->json('data.summary.subtotal')]);

        // Melebihi stok: ditolak, jumlah tidak berubah.
        $this->panggil('PATCH', $url, ['quantity' => 11], $token)
            ->assertStatus(422)->assertJsonPath('errors.quantity.0', 'Stok tidak cukup. Stok tersedia: 10.');
        $this->assertSame(5, CartItem::first()->quantity);

        foreach ([['quantity' => 0], ['quantity' => 100], ['quantity' => 'abc'], ['is_selected' => 'abc'], []] as $salah) {
            $this->panggil('PATCH', $url, $salah, $token)->assertStatus(422);
        }
        $this->assertSame(5, CartItem::first()->quantity);

        // Batal dipilih: tidak ikut subtotal.
        $res = $this->panggil('PATCH', $url, ['is_selected' => false], $token)->assertOk();
        $this->assertFalse($res->json('data.groups.0.items.0.is_selected'));
        $this->assertSame([0, 0, 0], [
            $res->json('data.summary.selected_count'),
            $res->json('data.summary.subtotal'),
            $res->json('data.groups.0.subtotal'),
        ]);

        // Keduanya sekaligus.
        $res = $this->panggil('PATCH', $url, ['quantity' => 3, 'is_selected' => true], $token)->assertOk();
        $this->assertSame([3, true, 300000], [
            $res->json('data.groups.0.items.0.quantity'),
            $res->json('data.groups.0.items.0.is_selected'),
            $res->json('data.summary.subtotal'),
        ]);

        // Item milik orang lain, varian tidak ada, dan bukan UUID: 404.
        [, $tokenLain] = $this->pembeli();
        $this->panggil('PATCH', $url, ['quantity' => 1], $tokenLain)->assertStatus(404);
        $this->panggil('DELETE', $url, [], $tokenLain)->assertStatus(404);
        $this->panggil('PATCH', '/api/v1/cart/items/' . self::UUID_ASING, ['quantity' => 1], $token)->assertStatus(404);
        $this->panggil('PATCH', '/api/v1/cart/items/abc', ['quantity' => 1], $token)->assertStatus(404);
        $this->assertSame(3, CartItem::first()->quantity);

        // Stok menjadi 0: jumlah tidak bisa diubah, tetapi pilihan masih bisa.
        $v->adjustStock(-10, 'manual');
        $this->panggil('PATCH', $url, ['quantity' => 1], $token)
            ->assertStatus(422)->assertJsonPath('errors.quantity.0', CartService::PESAN_STOK_HABIS);
        $this->panggil('PATCH', $url, ['is_selected' => true], $token)->assertOk();

        // Varian dinonaktifkan: jumlah tidak bisa diubah.
        $v->update(['is_active' => false]);
        $this->panggil('PATCH', $url, ['quantity' => 1], $token)
            ->assertStatus(422)->assertJsonPath('errors.quantity.0', 'Produk ini tidak lagi tersedia untuk dibeli.');
    }

    // ------------------------------------------------------------------ HAPUS

    public function test_hapus_item_dan_kosongkan_keranjang_hanya_menyentuh_milik_sendiri(): void
    {
        $toko = $this->toko();
        $kat  = $this->kategori();
        [$a, $tokenA] = $this->pembeli();
        [$b, $tokenB] = $this->pembeli();

        $v1 = $this->varian($toko, $kat);
        $v2 = $this->varian($toko, $kat);

        $this->isi($a, $v1);
        $this->isi($a, $v2);
        $this->isi($b, $v1);

        $this->panggil('DELETE', "/api/v1/cart/items/{$v1->uuid}", [], $tokenA)
            ->assertOk()->assertJson(['success' => true, 'data' => ['summary' => ['lines' => 1]]]);
        $this->assertSame(1, CartItem::where('user_id', $a->id)->count());
        $this->assertSame(1, CartItem::where('user_id', $b->id)->count());

        // Dihapus dua kali: 404.
        $this->panggil('DELETE', "/api/v1/cart/items/{$v1->uuid}", [], $tokenA)->assertStatus(404);

        $this->panggil('DELETE', '/api/v1/cart', [], $tokenA)
            ->assertOk()->assertJsonPath('data.groups', [])->assertJson(['data' => ['summary' => ['lines' => 0]]]);
        $this->assertSame(0, CartItem::where('user_id', $a->id)->count());

        // Keranjang pengguna lain utuh.
        $this->panggil('GET', '/api/v1/cart', [], $tokenB)->assertOk()->assertJson(['data' => ['summary' => ['lines' => 1]]]);
    }

    // ----------------------------------------------------------------- TAMPILAN

    public function test_tampilan_dikelompokkan_per_toko_dengan_status_dan_ringkasan(): void
    {
        $t1  = $this->toko('active', ['name' => 'Toko Satu', 'slug' => 'toko-satu']);
        $t2  = $this->toko('active', ['name' => 'Toko Dua', 'slug' => 'toko-dua']);
        $kat = $this->kategori();
        [$user, $token] = $this->pembeli();

        $a1 = $this->varian($t1, $kat, ['nama' => 'A1', 'harga' => 100000, 'stok' => 10]);
        $b1 = $this->varian($t2, $kat, ['nama' => 'B1', 'harga' => 30000, 'stok' => 10]);
        $a2 = $this->varian($t1, $kat, ['nama' => 'A2', 'harga' => 50000, 'stok' => 3]);
        $b2 = $this->varian($t2, $kat, ['nama' => 'B2', 'stok' => 0]);
        $b3 = $this->varian($t2, $kat, ['nama' => 'B3', 'status' => 'inactive']);
        $a3 = $this->varian($t1, $kat, ['nama' => 'A3', 'harga' => 10000, 'stok' => 5]);

        // Urutan pembuatan menentukan urutan tampil.
        $this->isi($user, $a1, 2);
        $this->isi($user, $b1, 1);
        $this->isi($user, $a2, 5);          // melebihi stok 3
        $this->isi($user, $b2, 1);          // stok habis
        $this->isi($user, $b3, 1);          // produk nonaktif
        $this->isi($user, $a3, 1, false);   // tidak dipilih

        $res = $this->panggil('GET', '/api/v1/cart', [], $token)->assertOk();

        $this->assertCount(2, $res->json('data.groups'));
        $this->assertSame(['Toko Satu', 'toko-satu', 'Sleman', true], [
            $res->json('data.groups.0.store.name'),
            $res->json('data.groups.0.store.slug'),
            $res->json('data.groups.0.store.city'),
            $res->json('data.groups.0.store.is_open'),
        ]);
        $this->assertSame(36, strlen($res->json('data.groups.0.store.id')));
        $this->assertSame('Toko Dua', $res->json('data.groups.1.store.name'));

        $nama   = fn(int $g) => collect($res->json("data.groups.{$g}.items"))->pluck('variant.name')->all();
        $status = fn(int $g) => collect($res->json("data.groups.{$g}.items"))->pluck('status')->all();
        $alasan = fn(int $g) => collect($res->json("data.groups.{$g}.items"))->pluck('reason')->all();

        $this->assertSame(['A1', 'A2', 'A3'], $nama(0));
        $this->assertSame(['available', 'insufficient_stock', 'available'], $status(0));
        $this->assertSame([null, null, null], $alasan(0));
        $this->assertSame([1, 200000], [$res->json('data.groups.0.selected_count'), $res->json('data.groups.0.subtotal')]);

        $this->assertSame(['B1', 'B2', 'B3'], $nama(1));
        $this->assertSame(['available', 'out_of_stock', 'unavailable'], $status(1));
        $this->assertSame([null, null, 'product_unavailable'], $alasan(1));
        $this->assertSame([1, 30000], [$res->json('data.groups.1.selected_count'), $res->json('data.groups.1.subtotal')]);

        // Hanya item yang dipilih dan tersedia (A1 dan B1) yang dihitung.
        $this->assertSame(
            ['lines' => 6, 'quantity_total' => 11, 'selected_count' => 2, 'selected_quantity' => 3, 'subtotal' => 230000, 'total_weight_gram' => 600, 'has_issues' => true],
            $res->json('data.summary')
        );

        $this->assertStringNotContainsString('628123456789', $res->getContent());
        $this->assertStringNotContainsString('Jl. Rahasia', $res->getContent());
    }

    public function test_alasan_item_tidak_tersedia(): void
    {
        $kat     = $this->kategori();
        $katMati = $this->kategori(['is_active' => false]);
        [$user, $token] = $this->pembeli();

        // Setiap varian di toko berbeda agar urutan tampil sama dengan urutan pembuatan.
        $kasus = [
            'store_unavailable'    => $this->varian($this->toko('suspended'), $kat),
            'category_unavailable' => $this->varian($this->toko(), $katMati),
            'variant_inactive'     => $this->varian($this->toko(), $kat, ['varian_aktif' => false]),
            'product_unavailable'  => $this->varian($this->toko(), $kat, ['dihapus' => true]),
        ];

        foreach ($kasus as $v) {
            $this->isi($user, $v);
        }

        $res   = $this->panggil('GET', '/api/v1/cart', [], $token)->assertOk();
        $items = collect($res->json('data.groups'))->flatMap(fn($g) => $g['items']);

        $this->assertSame(array_fill(0, 4, 'unavailable'), $items->pluck('status')->all());
        $this->assertSame(array_keys($kasus), $items->pluck('reason')->all());
        $this->assertSame([0, 0, true], [
            $res->json('data.summary.selected_count'),
            $res->json('data.summary.subtotal'),
            $res->json('data.summary.has_issues'),
        ]);

        // Produk dari toko sendiri.
        $tokoSaya = $this->toko();
        $milikku  = $this->varian($tokoSaya, $kat);
        $this->isi($tokoSaya->owner, $milikku);

        $this->panggil('GET', '/api/v1/cart', [], $this->tokenPemilik($tokoSaya))
            ->assertOk()
            ->assertJsonPath('data.groups.0.items.0.status', 'unavailable')
            ->assertJsonPath('data.groups.0.items.0.reason', 'own_store');
    }

    public function test_harga_selalu_mengikuti_harga_dan_diskon_terbaru(): void
    {
        $v = $this->varian($this->toko(), $this->kategori(), ['harga' => 100000, 'stok' => 10]);
        [$user, $token] = $this->pembeli();
        $this->isi($user, $v, 2);

        $ambil = fn() => $this->panggil('GET', '/api/v1/cart', [], $token)->assertOk();

        $res = $ambil();
        $this->assertSame([100000, 100000, false, 200000], [
            $res->json('data.groups.0.items.0.price'),
            $res->json('data.groups.0.items.0.final_price'),
            $res->json('data.groups.0.items.0.discount_active'),
            $res->json('data.summary.subtotal'),
        ]);

        // Penjual menaikkan harga: keranjang langsung mengikuti.
        $v->update(['price' => 120000]);
        $res = $ambil();
        $this->assertSame([120000, 240000], [$res->json('data.groups.0.items.0.final_price'), $res->json('data.summary.subtotal')]);

        // Diskon yang sedang berjalan.
        $v->update([
            'discount_price'     => 90000,
            'discount_starts_at' => now()->subDay(),
            'discount_ends_at'   => now()->addDay(),
        ]);
        $res = $ambil();
        $this->assertSame([120000, 90000, true, 180000, 180000], [
            $res->json('data.groups.0.items.0.price'),
            $res->json('data.groups.0.items.0.final_price'),
            $res->json('data.groups.0.items.0.discount_active'),
            $res->json('data.groups.0.items.0.line_total'),
            $res->json('data.summary.subtotal'),
        ]);

        // Diskon berakhir: harga kembali normal.
        $v->update(['discount_ends_at' => now()->subMinute()]);
        $res = $ambil();
        $this->assertSame([120000, false, 240000], [
            $res->json('data.groups.0.items.0.final_price'),
            $res->json('data.groups.0.items.0.discount_active'),
            $res->json('data.summary.subtotal'),
        ]);
    }

    // ----------------------------------------------------------------- PILIH MASSAL

    public function test_pilih_massal_hanya_menyentuh_item_milik_sendiri_dan_menolak_masukan_salah(): void
    {
        $toko = $this->toko();
        $kat  = $this->kategori();
        [$user, $token] = $this->pembeli();
        [$lain]         = $this->pembeli();

        $a = $this->varian($toko, $kat);
        $b = $this->varian($toko, $kat);
        $c = $this->varian($toko, $kat);
        $x = $this->varian($toko, $kat);

        $this->isi($user, $a);
        $this->isi($user, $b);
        $this->isi($user, $c);
        $this->isi($lain, $x, 1, false);   // keranjang orang lain, sengaja tidak dipilih

        $dipilih = fn(ProductVariant $v, User $u) => (bool) CartItem::where('user_id', $u->id)->where('variant_id', $v->id)->value('is_selected');

        $res = $this->panggil('POST', '/api/v1/cart/select', ['variant_ids' => [$a->uuid, $b->uuid], 'selected' => false], $token)
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame([false, false, true], [$dipilih($a, $user), $dipilih($b, $user), $dipilih($c, $user)]);
        $this->assertSame(1, $res->json('data.summary.selected_count'));

        // Varian yang tidak ada di keranjang dan milik orang lain diabaikan; yang valid tetap diproses.
        $this->panggil('POST', '/api/v1/cart/select', [
            'variant_ids' => [$a->uuid, self::UUID_ASING, $x->uuid],
            'selected' => true,
        ], $token)->assertOk();

        $this->assertTrue($dipilih($a, $user));
        $this->assertFalse($dipilih($b, $user));
        $this->assertFalse($dipilih($x, $lain));      // keranjang orang lain tidak tersentuh

        $seratusSatu = array_map(fn() => (string) Str::uuid(), range(1, 101));

        $kasus = [
            [[], ['variant_ids', 'selected']],
            [['variant_ids' => [], 'selected' => true], ['variant_ids']],
            [['variant_ids' => ['abc'], 'selected' => true], ['variant_ids.0']],
            [['variant_ids' => [$a->uuid, $a->uuid], 'selected' => true], ['variant_ids.0']],
            [['variant_ids' => $seratusSatu, 'selected' => true], ['variant_ids']],
            [['variant_ids' => [$a->uuid]], ['selected']],
            [['variant_ids' => [$a->uuid], 'selected' => 'abc'], ['selected']],
        ];

        foreach ($kasus as [$data, $kolom]) {
            $this->panggil('POST', '/api/v1/cart/select', $data, $token)
                ->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors($kolom);
        }
    }

    // ------------------------------------------------------------------ BATAS BARIS

    public function test_keranjang_dibatasi_seratus_baris_tetapi_item_yang_ada_tetap_bisa_ditambah(): void
    {
        $toko = $this->toko();
        [$user, $token] = $this->pembeli();

        $pertama = $this->varian($toko, $this->kategori(), ['nama' => 'V1', 'stok' => 10]);
        $produk  = $pertama->product;
        $ids     = [$pertama->id];

        for ($n = 2; $n <= 100; $n++) {
            $ids[] = $produk->variants()->create(['name' => "V{$n}", 'price' => 1000, 'weight_gram' => 100])->id;
        }

        CartItem::insert(array_map(fn(int $id) => [
            'user_id' => $user->id,
            'variant_id' => $id,
            'quantity' => 1,
            'is_selected' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $ids));

        $this->assertSame(100, CartItem::count());

        $ke101 = $produk->variants()->create(['name' => 'V101', 'price' => 1000, 'weight_gram' => 100]);
        $ke101->adjustStock(5, 'initial');

        // Baris ke-101 ditolak.
        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $ke101->uuid], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['cart']);
        $this->assertSame(100, CartItem::count());

        // Menambah jumlah pada baris yang sudah ada tetap boleh.
        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $pertama->uuid], $token)
            ->assertOk()->assertJson(['message' => 'Jumlah produk di keranjang diperbarui.']);
        $this->assertSame(100, CartItem::count());

        // Setelah satu baris dihapus, baris baru boleh masuk.
        $v2 = $produk->variants()->where('name', 'V2')->value('uuid');
        $this->panggil('DELETE', "/api/v1/cart/items/{$v2}", [], $token)->assertOk();
        $this->panggil('POST', '/api/v1/cart/items', ['variant_id' => $ke101->uuid], $token)->assertStatus(201);
        $this->assertSame(100, CartItem::count());
    }
}
