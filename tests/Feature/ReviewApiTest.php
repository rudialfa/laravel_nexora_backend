<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ReviewImage;
use App\Models\Store;
use App\Models\StoreReview;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SiapkanUlasan;
use Tests\TestCase;

/**
 * Menguji ulasan produk dan toko: pembuatan, aturan, daftar yang bisa dinilai, dan daftar publik.
 */
class ReviewApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanUlasan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturUlasan();
    }

    private function ulas(Order $o, string $token, array $data)
    {
        Cache::flush();   // penghitung laju dibersihkan: banyak tes mengirim lebih dari 10 permintaan

        return $this->kirimBerkas('POST', "/api/v1/orders/{$o->uuid}/reviews", $data, $token);
    }

    public function test_endpoint_ulasan_pembeli_butuh_login_dan_akun_aktif_sedangkan_daftar_publik_terbuka(): void
    {
        $u    = $this->uuidAsing();
        $rute = [
            ['GET', '/api/v1/reviews/pending'],
            ['POST', "/api/v1/orders/{$u}/reviews"],
            ['POST', "/api/v1/orders/{$u}/store-review"],
            ['POST', '/api/v1/products/abc/questions'],
        ];

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif] = $this->pembeli('suspended');

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
        }

        // Daftar publik tidak meminta login: produk atau toko yang tidak ada dijawab 404, bukan 401.
        foreach (['/api/v1/products/abc/reviews', '/api/v1/products/abc/questions', '/api/v1/stores/abc/reviews'] as $uri) {
            $this->kirim('GET', $uri)->assertStatus(404);
        }
    }

    public function test_ulasan_produk_menolak_masukan_tidak_valid(): void
    {
        [$user, $token] = $this->pembeli();
        $o              = $this->selesai($user);
        $v              = $this->varianUuid($o);
        $dasar          = ['variant_id' => $v, 'rating' => 5];

        $kasus = [
            [[], ['variant_id', 'rating']],
            [['variant_id' => 'abc', 'rating' => 5], 'variant_id'],
            [['variant_id' => $v, 'rating' => 0], 'rating'],
            [['variant_id' => $v, 'rating' => 6], 'rating'],
            [['variant_id' => $v, 'rating' => 'abc'], 'rating'],
            [$dasar + ['comment' => str_repeat('a', 1001)], 'comment'],
            [$dasar + ['is_anonymous' => 'abc'], 'is_anonymous'],
            [$dasar + ['photos' => $this->foto(6)], 'photos'],
            [$dasar + ['photos' => [UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf')]], 'photos.0'],
            [$dasar + ['photos' => [UploadedFile::fake()->create('gambar.svg', 10, 'image/svg+xml')]], 'photos.0'],
            [$dasar + ['photos' => [UploadedFile::fake()->image('besar.jpg')->size(6000)]], 'photos.0'],
        ];

        foreach ($kasus as [$data, $kolom]) {
            $this->ulas($o, $token, $data)->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors($kolom);
        }

        $this->assertSame(0, ProductReview::count());
    }

    public function test_pembeli_membuat_ulasan_produk_dengan_foto_yang_diproses_ulang_dan_rating_diperbarui(): void
    {
        [$user, $token] = $this->pembeli();
        $user->forceFill(['name' => 'Budi Santoso'])->save();

        $o      = $this->selesai($user);
        $produk = $this->produkDari($o);

        $res = $this->ulas($o, $token, [
            'variant_id'   => $this->varianUuid($o),
            'rating'       => 4,
            'comment'      => '  Barang bagus dan cepat sampai  ',
            'is_anonymous' => 0,
            'photos'       => [UploadedFile::fake()->image('a.jpg', 800, 600), UploadedFile::fake()->image('besar.png', 3000, 2000)],
        ])
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Ulasan berhasil dikirim.'])
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.comment', 'Barang bagus dan cepat sampai')
            ->assertJsonPath('data.author', 'B*** S***')
            ->assertJsonPath('data.seller_reply', null)
            ->assertJsonCount(2, 'data.images')
            ->assertJsonMissingPath('data.user_id');

        $this->assertStringNotContainsString($user->email, $res->getContent());

        $r = ProductReview::first();

        $this->assertSame(1, ProductReview::count());
        $this->assertSame($r->uuid, $res->json('data.id'));
        $this->assertSame([$user->id, $produk->id, 'published'], [(int) $r->user_id, (int) $r->product_id, $r->status]);
        $this->assertSame(2, ReviewImage::where('review_id', $r->id)->count());

        // Foto disimpan sebagai JPEG baru dan dikecilkan.
        foreach ([1, 2] as $n) {
            $path = "reviews/{$r->uuid}/{$n}.jpg";

            $this->assertTrue(Storage::disk('public')->exists($path));

            $info = getimagesizefromstring(Storage::disk('public')->get($path));

            $this->assertSame(IMAGETYPE_JPEG, $info[2]);
            $this->assertLessThanOrEqual(1600, max($info[0], $info[1]));
        }

        $this->assertSame(1067, getimagesizefromstring(Storage::disk('public')->get("reviews/{$r->uuid}/2.jpg"))[1]);

        // Rating produk diperbarui.
        $produk = $produk->fresh();

        $this->assertEquals(4, $produk->rating_avg);
        $this->assertSame(1, (int) $produk->rating_count);

        // Tanpa foto dan komentar kosong juga boleh, dan pilihan anonim dihormati.
        $o2 = $this->selesai($user);

        $this->ulas($o2, $token, ['variant_id' => $this->varianUuid($o2), 'rating' => 5, 'comment' => '', 'is_anonymous' => 1])
            ->assertStatus(201)->assertJsonPath('data.comment', null)->assertJsonPath('data.author', 'Pengguna Anonim')->assertJsonCount(0, 'data.images');
    }

    public function test_ulasan_ditolak_untuk_pesanan_yang_belum_selesai_lewat_batas_ganda_atau_milik_orang_lain(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();

        // Dibayar tetapi belum selesai.
        $belum = $this->lunas($this->checkout($user));

        $this->ulas($belum, $token, ['variant_id' => $this->varianUuid($belum), 'rating' => 5])
            ->assertStatus(422)->assertJsonPath('errors.order.0', ReviewService::PESAN_BELUM_SELESAI);

        // Selesai 31 hari lalu: lewat batas.
        $lama = $this->selesai($user);
        $lama->forceFill(['completed_at' => now()->subDays(31)])->save();

        $this->ulas($lama, $token, ['variant_id' => $this->varianUuid($lama), 'rating' => 5])
            ->assertStatus(422)->assertJsonPath('errors.order.0', ReviewService::PESAN_LEWAT_BATAS);

        // Selesai 29 hari lalu: masih dalam batas.
        $ok = $this->selesai($user);
        $ok->forceFill(['completed_at' => now()->subDays(29)])->save();
        $v  = $this->varianUuid($ok);

        $this->ulas($ok, $token, ['variant_id' => $v, 'rating' => 5])->assertStatus(201);

        // Ulasan kedua untuk item yang sama ditolak.
        $this->ulas($ok, $token, ['variant_id' => $v, 'rating' => 1])
            ->assertStatus(422)->assertJsonPath('errors.order.0', ReviewService::PESAN_SUDAH_ADA);

        // Pesanan orang lain dan varian yang bukan bagian pesanan: 404.
        $this->ulas($ok, $tokenLain, ['variant_id' => $v, 'rating' => 5])->assertStatus(404);
        $this->ulas($ok, $token, ['variant_id' => $this->uuidAsing(), 'rating' => 5])->assertStatus(404);

        $this->assertSame(1, ProductReview::count());
    }

    public function test_ulasan_toko_dibuat_sekali_per_pesanan_dan_rating_toko_diperbarui(): void
    {
        [$user, $token] = $this->pembeli();
        [, $tokenLain]  = $this->pembeli();
        $user->forceFill(['name' => 'Budi Santoso'])->save();

        $o   = $this->selesai($user);
        $url = "/api/v1/orders/{$o->uuid}/store-review";

        $this->kirim('POST', $url, ['rating' => 5, 'comment' => 'Penjual ramah'], $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Ulasan toko berhasil dikirim.'])
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.comment', 'Penjual ramah')
            ->assertJsonPath('data.author', 'B*** S***');

        $toko = Store::find($o->store_id);

        $this->assertEquals(5, $toko->rating_avg);
        $this->assertSame(1, (int) $toko->rating_count);
        $this->assertSame(1, StoreReview::count());

        $this->kirim('POST', $url, ['rating' => 1], $token)
            ->assertStatus(422)->assertJsonPath('errors.order.0', ReviewService::PESAN_SUDAH_ADA_TOKO);
        $this->kirim('POST', $url, ['rating' => 5], $tokenLain)->assertStatus(404);

        // Belum selesai: ditolak.
        $belum = $this->lunas($this->checkout($user));

        $this->kirim('POST', "/api/v1/orders/{$belum->uuid}/store-review", ['rating' => 5], $token)
            ->assertStatus(422)->assertJsonPath('errors.order.0', ReviewService::PESAN_BELUM_SELESAI);

        // Masukan tidak valid.
        $o2 = $this->selesai($user);

        foreach ([[[], 'rating'], [['rating' => 0], 'rating'], [['rating' => 6], 'rating'], [['rating' => 'abc'], 'rating'], [['rating' => 5, 'comment' => str_repeat('a', 1001)], 'comment']] as [$data, $kolom]) {
            Cache::flush();

            $this->kirim('POST', "/api/v1/orders/{$o2->uuid}/store-review", $data, $token)
                ->assertStatus(422)->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame(1, StoreReview::count());
    }

    public function test_daftar_yang_bisa_dinilai_menampilkan_hanya_pesanan_selesai_terbaru_yang_belum_dinilai(): void
    {
        [$user, $token] = $this->pembeli();
        [$lain]         = $this->pembeli();

        $a = $this->selesai($user);
        $b = $this->selesai($user);

        $lama = $this->selesai($user);
        $lama->forceFill(['completed_at' => now()->subDays(31)])->save();

        $this->lunas($this->checkout($user));   // belum selesai
        $this->selesai($lain);                  // milik orang lain

        $nomor = fn($res, $kunci) => collect($res->json($kunci))->pluck('order.number')->sort()->values()->all();
        $harap = collect([$a->number, $b->number])->sort()->values()->all();

        $res = $this->kirim('GET', '/api/v1/reviews/pending', [], $token)->assertOk()->assertJson(['success' => true]);

        $this->assertSame($harap, $nomor($res, 'data.products'));
        $this->assertSame($harap, $nomor($res, 'data.stores'));

        $entri = collect($res->json('data.products'))->firstWhere('order.number', $a->number);

        $this->assertSame($a->uuid, $entri['order']['id']);
        $this->assertSame($this->varianUuid($a), $entri['variant_id']);
        $this->assertSame($this->produkDari($a)->name, $entri['product']['name']);
        $this->assertSame($this->produkDari($a)->slug, $entri['product']['slug']);
        $this->assertSame([2, 'Default'], [$entri['quantity'], $entri['variant_name']]);
        $this->assertNotNull($entri['review_until']);

        // Setelah produk di pesanan A dinilai, hanya pesanan B yang tersisa untuk produk.
        $this->ulas($a, $token, ['variant_id' => $this->varianUuid($a), 'rating' => 5])->assertStatus(201);

        $res = $this->kirim('GET', '/api/v1/reviews/pending', [], $token)->assertOk();

        $this->assertSame([$b->number], $nomor($res, 'data.products'));
        $this->assertSame($harap, $nomor($res, 'data.stores'));

        // Setelah toko di pesanan B dinilai, hanya pesanan A yang tersisa untuk toko.
        $this->kirim('POST', "/api/v1/orders/{$b->uuid}/store-review", ['rating' => 4], $token)->assertStatus(201);

        $res = $this->kirim('GET', '/api/v1/reviews/pending', [], $token)->assertOk();

        $this->assertSame([$a->number], $nomor($res, 'data.stores'));
    }

    public function test_daftar_ulasan_produk_publik_dengan_ringkasan_filter_urutan_dan_paginasi(): void
    {
        [$u0] = $this->pembeli();
        $o    = $this->selesai($u0);
        $slug = $this->produkDari($o)->slug;

        $nama = ['Budi Santoso', 'Citra Dewi', 'Dian Putri', 'Eko Prasetyo', 'Fajar Nugroho'];
        $user = [];

        foreach ($nama as $n) {
            [$u] = $this->pembeli();
            $u->forceFill(['name' => $n])->save();
            $user[] = $u;
        }

        $r5 = $this->ulasanLangsung($o, $user[0], 5, ['foto' => true, 'balasan' => 'Terima kasih banyak']);
        $r4 = $this->ulasanLangsung($o, $user[1], 4);
        $ra = $this->ulasanLangsung($o, $user[2], 4, ['anonim' => true]);
        $r2 = $this->ulasanLangsung($o, $user[3], 2);
        $this->ulasanLangsung($o, $user[4], 1, ['status' => 'hidden']);

        $url = "/api/v1/products/{$slug}/reviews";
        $res = $this->kirim('GET', $url)->assertOk()->assertJson(['success' => true]);

        // Terbaru lebih dulu, ulasan tersembunyi tidak tampil.
        $this->assertSame([$r2->uuid, $ra->uuid, $r4->uuid, $r5->uuid], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(4, $res->json('meta.total'));

        // Ringkasan dihitung dari semua ulasan tayang: (5 + 4 + 4 + 2) / 4 = 3,75.
        $this->assertEquals(3.75, $res->json('summary.average'));
        $this->assertSame(4, $res->json('summary.count'));
        $this->assertSame([1, 2, 0, 1, 0], [
            $res->json('summary.distribution.5'),
            $res->json('summary.distribution.4'),
            $res->json('summary.distribution.3'),
            $res->json('summary.distribution.2'),
            $res->json('summary.distribution.1'),
        ]);

        // Nama tersamar, anonim, foto, dan balasan seller.
        $per = collect($res->json('data'))->keyBy('id');

        $this->assertSame('C*** D***', $per[$r4->uuid]['author']);
        $this->assertSame('Pengguna Anonim', $per[$ra->uuid]['author']);
        $this->assertSame('Terima kasih banyak', $per[$r5->uuid]['seller_reply']['text']);
        $this->assertCount(1, $per[$r5->uuid]['images']);
        $this->assertStringContainsString("reviews/{$r5->uuid}/1.jpg", $per[$r5->uuid]['images'][0]);
        $this->assertSame('Default', $per[$r5->uuid]['variant_name']);

        foreach ($nama as $n) {
            $this->assertStringNotContainsString($n, $res->getContent());
        }

        $this->assertStringNotContainsString('@nexora.test', $res->getContent());
        $this->assertStringNotContainsString('user_id', $res->getContent());

        // Filter, urutan, dan paginasi.
        $ids = fn(string $q) => collect($this->kirim('GET', $url . $q)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$ra->uuid, $r4->uuid], $ids('?rating=4'));
        $this->assertSame([$r5->uuid], $ids('?with_photos=1'));
        $this->assertSame($r5->uuid, $ids('?sort=highest')[0]);
        $this->assertSame($r2->uuid, $ids('?sort=lowest')[0]);
        $this->assertSame([$r4->uuid, $r5->uuid], $ids('?per_page=2&page=2'));

        $hal = $this->kirim('GET', $url . '?per_page=2&page=2')->assertOk();

        $this->assertSame([4, 2, 2], [$hal->json('meta.total'), $hal->json('meta.last_page'), $hal->json('meta.per_page')]);

        foreach (['?rating=6', '?rating=0', '?sort=abc', '?per_page=100', '?per_page=0', '?page=0', '?with_photos=abc'] as $salah) {
            $this->kirim('GET', $url . $salah)->assertStatus(422);
        }

        // Produk yang tidak tayang tidak menampilkan ulasan.
        $produk = $this->produkDari($o);
        $produk->forceFill(['status' => 'draft'])->save();

        $this->kirim('GET', $url)->assertStatus(404);
        $this->kirim('GET', '/api/v1/products/tidak-ada/reviews')->assertStatus(404);
    }

    public function test_daftar_ulasan_toko_publik_dengan_ringkasan_dan_hanya_toko_aktif(): void
    {
        [$u0] = $this->pembeli();
        $o    = $this->selesai($u0);
        $toko = Store::find($o->store_id);

        $buat = function (int $rating, string $status = 'published') use ($o) {
            [$u] = $this->pembeli();
            $u->forceFill(['name' => 'Gita Lestari'])->save();

            $pesanan = $o->replicate(['uuid']);
            $pesanan->forceFill(['number' => Order::generateNumber($o->checkoutGroup, random_int(10, 99999))])->save();

            $r = new StoreReview(['rating' => $rating, 'comment' => "Toko {$rating}"]);
            $r->forceFill(['order_id' => $pesanan->id, 'user_id' => $u->id, 'store_id' => $o->store_id, 'status' => $status])->save();

            return $r->fresh();
        };

        $r5 = $buat(5);
        $r3 = $buat(3);
        $r4 = $buat(4);
        $buat(1, 'hidden');

        $url = "/api/v1/stores/{$toko->slug}/reviews";
        $res = $this->kirim('GET', $url)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$r4->uuid, $r3->uuid, $r5->uuid], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(3, $res->json('meta.total'));
        $this->assertEquals(4, $res->json('summary.average'));
        $this->assertSame([3, 1, 1, 1], [$res->json('summary.count'), $res->json('summary.distribution.5'), $res->json('summary.distribution.4'), $res->json('summary.distribution.3')]);
        $this->assertSame('G*** L***', $res->json('data.0.author'));
        $this->assertStringNotContainsString('Gita', $res->getContent());

        $this->assertSame([$r5->uuid], collect($this->kirim('GET', $url . '?rating=5')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame($r3->uuid, $this->kirim('GET', $url . '?sort=lowest')->assertOk()->json('data.0.id'));
        $this->kirim('GET', $url . '?rating=9')->assertStatus(422);

        // Toko yang tidak aktif dan yang tidak ada: 404.
        $toko->forceFill(['status' => 'suspended'])->save();

        $this->kirim('GET', $url)->assertStatus(404);
        $this->kirim('GET', '/api/v1/stores/tidak-ada/reviews')->assertStatus(404);
    }

    public function test_pembuatan_ulasan_dibatasi_sepuluh_per_menit(): void
    {
        [, $token] = $this->pembeli();
        $url       = '/api/v1/orders/' . $this->uuidAsing() . '/reviews';

        for ($n = 1; $n <= 10; $n++) {
            $this->kirim('POST', $url, [], $token)->assertStatus(422);
        }

        $this->kirim('POST', $url, [], $token)->assertStatus(429);
    }
}
