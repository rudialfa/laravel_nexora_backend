<?php

namespace Tests\Feature;

use App\Models\ProductQuestion;
use App\Models\ProductReview;
use App\Services\QuestionService;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\SiapkanUlasan;
use Tests\TestCase;

/**
 * Menguji sisi seller (balas ulasan, jawab pertanyaan) dan pertanyaan dari pembeli.
 */
class FeedbackApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanUlasan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturUlasan();
    }

    private function ids($res): array
    {
        return collect($res->json('data'))->pluck('id')->all();
    }

    public function test_endpoint_feedback_seller_butuh_login_akun_aktif_dan_peran_seller(): void
    {
        $u    = $this->uuidAsing();
        $rute = [
            ['GET', '/api/v1/seller/reviews'],
            ['POST', "/api/v1/seller/reviews/{$u}/reply"],
            ['GET', '/api/v1/seller/questions'],
            ['POST', "/api/v1/seller/questions/{$u}/answer"],
        ];

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif] = $this->pembeli('suspended');
        [, $biasa]    = $this->pembeli();

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
            $this->kirim($method, $uri, [], $biasa)->assertStatus(403);
        }
    }

    public function test_seller_melihat_ulasan_produk_tokonya_dengan_filter_dan_membalas_sekali(): void
    {
        [$u0] = $this->pembeli();
        [$u1] = $this->pembeli();
        [$u2] = $this->pembeli();
        [$u3] = $this->pembeli();
        $u1->forceFill(['name' => 'Budi Santoso'])->save();

        $o     = $this->selesai($u0);
        $token = $this->penjual($o);
        $slug  = $this->produkDari($o)->slug;

        $o2        = $this->selesai($u0);   // toko lain
        $tokenLain = $this->penjual($o2);

        $r5 = $this->ulasanLangsung($o, $u1, 5);
        $r3 = $this->ulasanLangsung($o, $u2, 3, ['balasan' => 'Sudah dibalas sebelumnya']);
        $rh = $this->ulasanLangsung($o, $u3, 1, ['status' => 'hidden']);
        $rl = $this->ulasanLangsung($o2, $u1, 4);

        $res = $this->kirim('GET', '/api/v1/seller/reviews', [], $token)->assertOk()->assertJson(['success' => true]);

        // Hanya ulasan tayang untuk produk tokonya sendiri.
        $this->assertSame([$r3->uuid, $r5->uuid], $this->ids($res));
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame([$slug, $slug], collect($res->json('data'))->pluck('product.slug')->all());
        $this->assertSame('B*** S***', collect($res->json('data'))->firstWhere('id', $r5->uuid)['author']);

        $this->assertSame([$r5->uuid], $this->ids($this->kirim('GET', '/api/v1/seller/reviews?replied=0', [], $token)->assertOk()));
        $this->assertSame([$r3->uuid], $this->ids($this->kirim('GET', '/api/v1/seller/reviews?replied=1', [], $token)->assertOk()));
        $this->assertSame([$r3->uuid], $this->ids($this->kirim('GET', '/api/v1/seller/reviews?rating=3', [], $token)->assertOk()));
        $this->kirim('GET', '/api/v1/seller/reviews?rating=9', [], $token)->assertStatus(422);

        // Membalas ulasan.
        $url = "/api/v1/seller/reviews/{$r5->uuid}/reply";

        foreach ([[[], 'reply'], [['reply' => 'a'], 'reply'], [['reply' => str_repeat('a', 501)], 'reply']] as [$data, $kolom]) {
            $this->kirim('POST', $url, $data, $token)->assertStatus(422)->assertJsonValidationErrors([$kolom]);
        }

        $this->assertNull($r5->fresh()->seller_reply);

        $this->kirim('POST', $url, ['reply' => 'Terima kasih atas ulasannya'], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Balasan terkirim.'])
            ->assertJsonPath('data.seller_reply.text', 'Terima kasih atas ulasannya')
            ->assertJsonPath('data.product.slug', $slug);

        $this->assertSame('Terima kasih atas ulasannya', $r5->fresh()->seller_reply);
        $this->assertNotNull($r5->fresh()->seller_replied_at);

        // Balasan hanya satu kali.
        $this->kirim('POST', $url, ['reply' => 'Balasan kedua'], $token)
            ->assertStatus(422)->assertJsonPath('errors.reply.0', ReviewService::PESAN_SUDAH_DIBALAS);
        $this->assertSame('Terima kasih atas ulasannya', $r5->fresh()->seller_reply);

        // Ulasan toko lain, ulasan tersembunyi, dan yang tidak ada: 404.
        $this->kirim('POST', "/api/v1/seller/reviews/{$rl->uuid}/reply", ['reply' => 'Balasan uji'], $token)->assertStatus(404);
        $this->kirim('POST', "/api/v1/seller/reviews/{$rh->uuid}/reply", ['reply' => 'Balasan uji'], $token)->assertStatus(404);
        $this->kirim('POST', '/api/v1/seller/reviews/' . $this->uuidAsing() . '/reply', ['reply' => 'Balasan uji'], $token)->assertStatus(404);
        $this->kirim('POST', "/api/v1/seller/reviews/{$r3->uuid}/reply", ['reply' => 'Balasan uji'], $tokenLain)->assertStatus(404);

        // Seller lain tidak melihat ulasan toko ini, dan balasan tampil di daftar publik.
        $this->assertSame([$rl->uuid], $this->ids($this->kirim('GET', '/api/v1/seller/reviews', [], $tokenLain)->assertOk()));

        $publik = collect($this->kirim('GET', "/api/v1/products/{$slug}/reviews")->assertOk()->json('data'))->firstWhere('id', $r5->uuid);

        $this->assertSame('Terima kasih atas ulasannya', $publik['seller_reply']['text']);
        $this->assertSame(1, ProductReview::where('status', 'hidden')->count());
    }

    public function test_pembeli_bertanya_dengan_batas_per_hari_dan_tidak_pada_produk_toko_sendiri(): void
    {
        [$u0]           = $this->pembeli();
        [$user, $token] = $this->pembeli();
        $user->forceFill(['name' => 'Budi Santoso'])->save();

        $o          = $this->selesai($u0);
        $tokenOwner = $this->penjual($o);
        $produk     = $this->produkDari($o);
        $url        = "/api/v1/products/{$produk->slug}/questions";

        $res = $this->kirim('POST', $url, ['question' => 'Apakah ukuran 42 masih tersedia?'], $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Pertanyaan terkirim.'])
            ->assertJsonPath('data.question', 'Apakah ukuran 42 masih tersedia?')
            ->assertJsonPath('data.asker', 'B*** S***')
            ->assertJsonPath('data.answer', null);

        $this->assertStringNotContainsString($user->email, $res->getContent());

        $q = ProductQuestion::first();

        $this->assertSame($q->uuid, $res->json('data.id'));
        $this->assertSame([$user->id, $produk->id, 'published'], [(int) $q->user_id, (int) $q->product_id, $q->status]);

        // Masukan tidak valid.
        foreach ([[[], 'question'], [['question' => 'abc'], 'question'], [['question' => str_repeat('a', 501)], 'question']] as [$data, $kolom]) {
            $this->kirim('POST', $url, $data, $token)->assertStatus(422)->assertJsonValidationErrors([$kolom]);
        }

        // Pemilik toko tidak bisa bertanya pada produknya sendiri.
        $this->kirim('POST', $url, ['question' => 'Pertanyaan dari pemilik toko'], $tokenOwner)
            ->assertStatus(422)->assertJsonPath('errors.question.0', QuestionService::PESAN_TOKO_SENDIRI);

        // Batas lima pertanyaan per produk dalam 24 jam.
        Cache::flush();

        foreach ([2, 3, 4, 5] as $n) {
            $this->kirim('POST', $url, ['question' => "Pertanyaan nomor {$n} tentang produk"], $token)->assertStatus(201);
        }

        $res = $this->kirim('POST', $url, ['question' => 'Pertanyaan keenam tentang produk'], $token)->assertStatus(422);

        $this->assertStringContainsString('Batas 5 pertanyaan', $res->json('errors.question.0'));
        $this->assertSame(5, ProductQuestion::count());

        // Pembeli lain tidak ikut terbatasi.
        [, $tokenLain] = $this->pembeli();

        $this->kirim('POST', $url, ['question' => 'Pertanyaan dari pembeli lain'], $tokenLain)->assertStatus(201);

        // Produk yang tidak tayang dan yang tidak ada: 404.
        $this->kirim('POST', '/api/v1/products/tidak-ada/questions', ['question' => 'Pertanyaan uji produk'], $token)->assertStatus(404);

        $produk->forceFill(['status' => 'draft'])->save();

        $this->kirim('POST', $url, ['question' => 'Pertanyaan pada produk draf'], $tokenLain)->assertStatus(404);
    }

    public function test_seller_menjawab_pertanyaan_sekali_dan_daftar_publik_menampilkannya(): void
    {
        [$u0] = $this->pembeli();
        [$u1] = $this->pembeli();
        [$u2] = $this->pembeli();
        $u1->forceFill(['name' => 'Budi Santoso'])->save();

        $o         = $this->selesai($u0);
        $token     = $this->penjual($o);
        $produk    = $this->produkDari($o);
        $o2        = $this->selesai($u0);
        $tokenLain = $this->penjual($o2);

        $q1 = $this->tanyaLangsung($produk, $u1, 'Apakah barang ini ready stok?');
        $q2 = $this->tanyaLangsung($produk, $u2, 'Apakah ada garansi toko?', ['jawaban' => 'Ada, garansi tujuh hari']);
        $q3 = $this->tanyaLangsung($produk, $u1, 'Pertanyaan yang disembunyikan', ['status' => 'hidden']);
        $q4 = $this->tanyaLangsung($this->produkDari($o2), $u1, 'Pertanyaan untuk toko lain');

        // Daftar seller: hanya pertanyaan tayang untuk produk tokonya.
        $res = $this->kirim('GET', '/api/v1/seller/questions', [], $token)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$q2->uuid, $q1->uuid], $this->ids($res));
        $this->assertSame($produk->slug, $res->json('data.1.product.slug'));
        $this->assertSame('B*** S***', $res->json('data.1.asker'));
        $this->assertSame([$q1->uuid], $this->ids($this->kirim('GET', '/api/v1/seller/questions?answered=0', [], $token)->assertOk()));
        $this->assertSame([$q2->uuid], $this->ids($this->kirim('GET', '/api/v1/seller/questions?answered=1', [], $token)->assertOk()));
        $this->kirim('GET', '/api/v1/seller/questions?answered=abc', [], $token)->assertStatus(422);

        // Menjawab.
        $url = "/api/v1/seller/questions/{$q1->uuid}/answer";

        foreach ([[[], 'answer'], [['answer' => 'a'], 'answer'], [['answer' => str_repeat('a', 1001)], 'answer']] as [$data, $kolom]) {
            $this->kirim('POST', $url, $data, $token)->assertStatus(422)->assertJsonValidationErrors([$kolom]);
        }

        $this->assertNull($q1->fresh()->answer);

        $this->kirim('POST', $url, ['answer' => 'Ready, silakan dipesan'], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Jawaban terkirim.'])
            ->assertJsonPath('data.answer.text', 'Ready, silakan dipesan')
            ->assertJsonPath('data.answer.by', $o->store->name);

        $this->assertSame('Ready, silakan dipesan', $q1->fresh()->answer);
        $this->assertSame($o->store->owner_id, (int) $q1->fresh()->answered_by);
        $this->assertNotNull($q1->fresh()->answered_at);

        // Jawaban hanya satu kali.
        $this->kirim('POST', $url, ['answer' => 'Jawaban kedua'], $token)
            ->assertStatus(422)->assertJsonPath('errors.answer.0', QuestionService::PESAN_SUDAH_DIJAWAB);
        $this->assertSame('Ready, silakan dipesan', $q1->fresh()->answer);

        // Pertanyaan toko lain, yang disembunyikan, dan yang tidak ada: 404.
        foreach ([$q4, $q3] as $x) {
            $this->kirim('POST', "/api/v1/seller/questions/{$x->uuid}/answer", ['answer' => 'Jawaban uji'], $token)->assertStatus(404);
        }

        $this->kirim('POST', '/api/v1/seller/questions/' . $this->uuidAsing() . '/answer', ['answer' => 'Jawaban uji'], $token)->assertStatus(404);
        $this->kirim('POST', "/api/v1/seller/questions/{$q2->uuid}/answer", ['answer' => 'Jawaban uji'], $tokenLain)->assertStatus(404);

        // Daftar publik: pertanyaan tersembunyi tidak tampil, nama tersamar, dan jawaban memuat nama toko.
        $pub = $this->kirim('GET', "/api/v1/products/{$produk->slug}/questions")->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$q2->uuid, $q1->uuid], $this->ids($pub));
        $this->assertSame(2, $pub->json('meta.total'));
        $this->assertSame('Ready, silakan dipesan', $pub->json('data.1.answer.text'));
        $this->assertSame($o->store->name, $pub->json('data.1.answer.by'));
        $this->assertStringNotContainsString('Budi', $pub->getContent());
        $this->assertStringNotContainsString('@nexora.test', $pub->getContent());

        $this->assertCount(2, $this->kirim('GET', "/api/v1/products/{$produk->slug}/questions?answered=1")->json('data'));
        $this->assertCount(0, $this->kirim('GET', "/api/v1/products/{$produk->slug}/questions?answered=0")->json('data'));
        $this->kirim('GET', "/api/v1/products/{$produk->slug}/questions?answered=abc")->assertStatus(422);

        $produk->forceFill(['status' => 'draft'])->save();

        $this->kirim('GET', "/api/v1/products/{$produk->slug}/questions")->assertStatus(404);
    }
}
