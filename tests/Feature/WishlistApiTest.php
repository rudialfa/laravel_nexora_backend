<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Store;
use App\Models\Wishlist;
use App\Services\WishlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SiapkanSosial;
use Tests\TestCase;

/**
 * Menguji wishlist pembeli lewat HTTP.
 */
class WishlistApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanSosial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturSosial();
    }

    private function tambah(string $token, string $uuid)
    {
        return $this->kirim('POST', '/api/v1/wishlist', ['product_id' => $uuid], $token);
    }

    private function cek(string $token, array $ids)
    {
        return $this->kirim('GET', '/api/v1/wishlist/check?' . http_build_query(['product_ids' => $ids]), [], $token);
    }

    public function test_wishlist_butuh_login_dan_akun_aktif(): void
    {
        $u    = $this->uuidAsing();
        $rute = [['GET', '/api/v1/wishlist'], ['GET', '/api/v1/wishlist/check'], ['POST', '/api/v1/wishlist'], ['DELETE', "/api/v1/wishlist/{$u}"]];

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif] = $this->pembeli('suspended');

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
        }
    }

    public function test_wishlist_menolak_masukan_tidak_valid(): void
    {
        [, $token] = $this->pembeli();

        $this->kirim('POST', '/api/v1/wishlist', [], $token)->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors(['product_id']);
        $this->kirim('POST', '/api/v1/wishlist', ['product_id' => 'abc'], $token)->assertStatus(422)->assertJsonValidationErrors(['product_id']);

        $this->kirim('GET', '/api/v1/wishlist/check', [], $token)->assertStatus(422)->assertJsonValidationErrors(['product_ids']);
        $this->cek($token, ['abc'])->assertStatus(422)->assertJsonValidationErrors(['product_ids.0']);

        $sama = (string) Str::uuid();
        $this->cek($token, [$sama, $sama])->assertStatus(422)->assertJsonValidationErrors(['product_ids.0']);

        $banyak = array_map(fn() => (string) Str::uuid(), range(1, 51));
        $this->cek($token, $banyak)->assertStatus(422)->assertJsonValidationErrors(['product_ids']);

        foreach (['?per_page=100', '?per_page=0', '?page=0', '?q=' . str_repeat('a', 101)] as $salah) {
            $this->kirim('GET', "/api/v1/wishlist{$salah}", [], $token)->assertStatus(422);
        }
    }

    public function test_menambah_produk_ke_wishlist_aman_diulang_dan_terpisah_per_pengguna(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();

        $a = $this->produk();
        $b = $this->produk();

        $this->tambah($token, $a->uuid)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Produk ditambahkan ke wishlist.'])
            ->assertJsonPath('data.in_wishlist', true)
            ->assertJsonPath('data.count', 1);

        $this->assertSame(1, Wishlist::count());
        $this->assertSame([$user->id, $a->id], [(int) Wishlist::first()->user_id, (int) Wishlist::first()->product_id]);

        // Diulang: sukses tanpa menggandakan.
        $this->tambah($token, $a->uuid)
            ->assertOk()->assertJson(['message' => 'Produk sudah ada di wishlist.'])->assertJsonPath('data.count', 1);

        $this->assertSame(1, Wishlist::count());

        $this->tambah($token, $b->uuid)->assertStatus(201)->assertJsonPath('data.count', 2);

        // Pengguna lain menyimpan produk yang sama tanpa saling mengganggu.
        $this->tambah($tokenLain, $a->uuid)->assertStatus(201)->assertJsonPath('data.count', 1);

        $this->assertSame(3, Wishlist::count());
    }

    public function test_produk_yang_tidak_tayang_atau_milik_toko_sendiri_ditolak(): void
    {
        [, $token] = $this->pembeli();

        $draft = $this->produk(['status' => 'draft']);

        $tokoBlokir = $this->tokoBaru();
        $pBlokir    = $this->produk(['toko' => $tokoBlokir]);
        $tokoBlokir->forceFill(['status' => 'suspended'])->save();

        $pKategori = $this->produk();
        Category::find($pKategori->category_id)->forceFill(['is_active' => false])->save();

        $pHapus = $this->produk();
        $pHapus->delete();

        foreach ([$draft->uuid, $pBlokir->uuid, $pKategori->uuid, $pHapus->uuid, $this->uuidAsing()] as $uuid) {
            $this->tambah($token, $uuid)
                ->assertStatus(422)->assertJson(['success' => false])
                ->assertJsonPath('errors.product_id.0', WishlistService::PESAN_TIDAK_TERSEDIA);
        }

        // Produk dari toko sendiri.
        $toko      = $this->tokoBaru();
        $milikku   = $this->produk(['toko' => $toko]);
        $tokenOwner = $toko->owner->createToken('test', ['*'], now()->addDay())->plainTextToken;

        $this->tambah($tokenOwner, $milikku->uuid)
            ->assertStatus(422)->assertJsonPath('errors.product_id.0', WishlistService::PESAN_TOKO_SENDIRI);

        $this->assertSame(0, Wishlist::count());
    }

    public function test_wishlist_dibatasi_jumlahnya_tetapi_produk_yang_sudah_ada_tetap_sukses(): void
    {
        config(['wishlist.max' => 2]);

        [, $token] = $this->pembeli();

        $a = $this->produk();
        $b = $this->produk();
        $c = $this->produk();

        $this->tambah($token, $a->uuid)->assertStatus(201);
        $this->tambah($token, $b->uuid)->assertStatus(201);

        $res = $this->tambah($token, $c->uuid)->assertStatus(422);

        $this->assertStringContainsString('maksimal 2', $res->json('errors.product_id.0'));
        $this->assertSame(2, Wishlist::count());

        // Produk yang sudah ada tidak dihitung sebagai tambahan baru.
        $this->tambah($token, $a->uuid)->assertOk();

        // Setelah satu dihapus, produk baru boleh masuk.
        $this->kirim('DELETE', "/api/v1/wishlist/{$a->uuid}", [], $token)->assertOk();
        $this->tambah($token, $c->uuid)->assertStatus(201);

        $this->assertSame(2, Wishlist::count());
    }

    public function test_menghapus_dari_wishlist_selalu_berhasil_dan_tidak_menyentuh_milik_orang_lain(): void
    {
        [$user, $token] = $this->pembeli();
        [$lain, $tokenLain] = $this->pembeli();

        $a = $this->produk();

        $this->tambah($token, $a->uuid)->assertStatus(201);
        $this->tambah($tokenLain, $a->uuid)->assertStatus(201);

        $url = "/api/v1/wishlist/{$a->uuid}";

        $this->kirim('DELETE', $url, [], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Produk dihapus dari wishlist.'])
            ->assertJsonPath('data.in_wishlist', false)
            ->assertJsonPath('data.count', 0);

        $this->assertSame(0, Wishlist::where('user_id', $user->id)->count());
        $this->assertSame(1, Wishlist::where('user_id', $lain->id)->count());

        // Diulang, dan produk yang tidak dikenal: tetap 200.
        $this->kirim('DELETE', $url, [], $token)->assertOk();
        $this->kirim('DELETE', '/api/v1/wishlist/' . $this->uuidAsing(), [], $token)->assertOk();

        // Bukan UUID: 404.
        $this->kirim('DELETE', '/api/v1/wishlist/abc', [], $token)->assertStatus(404);
    }

    public function test_daftar_wishlist_menampilkan_produk_tidak_tayang_dengan_alasannya_dan_mendukung_pencarian(): void
    {
        [$user, $token]     = $this->pembeli();
        [$lain, $tokenLain] = $this->pembeli();

        $p1 = $this->produk(['nama' => 'Sepatu Lari', 'harga' => 350000]);
        $p2 = $this->produk(['nama' => 'Tas Ransel']);
        $p3 = $this->produk(['nama' => 'Topi Pantai']);
        $p4 = $this->produk(['nama' => 'Jaket Hujan']);
        $p5 = $this->produk(['nama' => 'Botol Minum']);

        foreach ([$p1, $p2, $p3, $p4, $p5] as $p) {
            Wishlist::create(['user_id' => $user->id, 'product_id' => $p->id]);
        }

        Wishlist::create(['user_id' => $lain->id, 'product_id' => $p1->id]);

        // Setelah disimpan, produk-produk ini berubah keadaan.
        $p2->forceFill(['status' => 'draft'])->save();
        Store::find($p3->store_id)->forceFill(['status' => 'suspended'])->save();
        Category::find($p4->category_id)->forceFill(['is_active' => false])->save();
        $p5->delete();

        $res = $this->kirim('GET', '/api/v1/wishlist', [], $token)->assertOk()->assertJson(['success' => true]);

        // Terbaru disimpan tampil lebih dulu.
        $this->assertSame([$p5->uuid, $p4->uuid, $p3->uuid, $p2->uuid, $p1->uuid], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(5, $res->json('meta.total'));

        $per = collect($res->json('data'))->keyBy('id');

        $this->assertSame(['product_unavailable', false], [$per[$p5->uuid]['reason'], $per[$p5->uuid]['available']]);
        $this->assertSame('category_unavailable', $per[$p4->uuid]['reason']);
        $this->assertSame('store_unavailable', $per[$p3->uuid]['reason']);
        $this->assertSame('product_unavailable', $per[$p2->uuid]['reason']);
        $this->assertSame([null, true], [$per[$p1->uuid]['reason'], $per[$p1->uuid]['available']]);

        $kartu = $per[$p1->uuid];

        $this->assertSame(['Sepatu Lari', $p1->slug, 350000, 350000], [$kartu['name'], $kartu['slug'], $kartu['price_min'], $kartu['price_max']]);
        $this->assertStringContainsString('products/foto-', $kartu['image_url']);
        $this->assertSame($p1->store->name, $kartu['store']['name']);
        $this->assertNotNull($kartu['added_at']);

        $this->assertStringNotContainsString('@nexora.test', $res->getContent());
        $this->assertStringNotContainsString('user_id', $res->getContent());

        // Pencarian: huruf kecil cocok, karakter khusus diperlakukan apa adanya.
        $ids = fn(string $q) => collect($this->kirim('GET', '/api/v1/wishlist' . $q, [], $token)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$p1->uuid], $ids('?q=sepatu'));
        $this->assertSame([$p2->uuid], $ids('?q=RANSEL'));
        $this->assertSame([], $ids('?q=' . urlencode('%')));
        $this->assertSame([], $ids('?q=' . urlencode('_')));

        // Paginasi.
        $hal = $this->kirim('GET', '/api/v1/wishlist?per_page=2&page=2', [], $token)->assertOk();

        $this->assertSame([$p3->uuid, $p2->uuid], collect($hal->json('data'))->pluck('id')->all());
        $this->assertSame([5, 3, 2], [$hal->json('meta.total'), $hal->json('meta.last_page'), $hal->json('meta.per_page')]);

        // Pengguna lain hanya melihat miliknya.
        $this->assertSame([$p1->uuid], collect($this->kirim('GET', '/api/v1/wishlist', [], $tokenLain)->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_cek_banyak_produk_menandai_yang_ada_di_wishlist_termasuk_yang_sudah_dihapus(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();

        $a = $this->produk();
        $b = $this->produk();
        $c = $this->produk();

        Wishlist::create(['user_id' => $user->id, 'product_id' => $a->id]);
        Wishlist::create(['user_id' => $user->id, 'product_id' => $b->id]);
        $b->delete();

        $res = $this->cek($token, [$a->uuid, $b->uuid, $c->uuid, $this->uuidAsing()])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(
            [$a->uuid => true, $b->uuid => true, $c->uuid => false, $this->uuidAsing() => false],
            $res->json('data')
        );

        // Milik pengguna lain tidak terbaca.
        $this->assertSame([$a->uuid => false], $this->cek($tokenLain, [$a->uuid])->assertOk()->json('data'));
    }

    public function test_penambahan_wishlist_dibatasi_tiga_puluh_per_menit(): void
    {
        [, $token] = $this->pembeli();

        for ($n = 1; $n <= 30; $n++) {
            $this->kirim('POST', '/api/v1/wishlist', [], $token)->assertStatus(422);
        }

        $this->kirim('POST', '/api/v1/wishlist', [], $token)->assertStatus(429);
    }
}
