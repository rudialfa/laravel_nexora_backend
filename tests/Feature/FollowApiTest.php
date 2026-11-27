<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreFollower;
use App\Services\FollowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SiapkanSosial;
use Tests\TestCase;

/**
 * Menguji ikuti toko lewat HTTP: pembeli, daftar toko yang diikuti, dan sisi seller.
 */
class FollowApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanSosial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturSosial();
    }

    private function ikuti(string $token, string $slug)
    {
        return $this->kirim('POST', "/api/v1/stores/{$slug}/follow", [], $token);
    }

    private function henti(string $token, string $slug)
    {
        return $this->kirim('DELETE', "/api/v1/stores/{$slug}/follow", [], $token);
    }

    private function slug($res): array
    {
        return collect($res->json('data'))->pluck('slug')->all();
    }

    public function test_endpoint_mengikuti_butuh_login_akun_aktif_dan_peran_yang_sesuai(): void
    {
        $rute = [['POST', '/api/v1/stores/abc/follow'], ['DELETE', '/api/v1/stores/abc/follow'], ['GET', '/api/v1/following'], ['GET', '/api/v1/seller/followers']];

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif] = $this->pembeli('suspended');
        [, $biasa]    = $this->pembeli();

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
        }

        // Daftar pengikut khusus seller.
        $this->kirim('GET', '/api/v1/seller/followers', [], $biasa)->assertStatus(403);
    }

    public function test_mengikuti_toko_aman_diulang_dan_jumlah_pengikut_tersinkron(): void
    {
        $toko = $this->tokoBaru();

        [, $t1] = $this->pembeli();
        [, $t2] = $this->pembeli();

        $this->ikuti($t1, $toko->slug)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Anda mengikuti toko ini.'])
            ->assertJsonPath('data.following', true)
            ->assertJsonPath('data.followers_count', 1);

        // Diulang: sukses tanpa menggandakan.
        $this->ikuti($t1, $toko->slug)
            ->assertOk()->assertJson(['message' => 'Anda sudah mengikuti toko ini.'])->assertJsonPath('data.followers_count', 1);

        $this->ikuti($t2, $toko->slug)->assertStatus(201)->assertJsonPath('data.followers_count', 2);

        $this->assertSame(2, StoreFollower::count());
        $this->assertSame(2, (int) Store::find($toko->id)->followers_count);
    }

    public function test_toko_sendiri_toko_tidak_aktif_dan_toko_yang_tidak_ada_ditolak(): void
    {
        [, $token] = $this->pembeli();

        $toko       = $this->tokoBaru();
        $tokenOwner = $toko->owner->createToken('test', ['*'], now()->addDay())->plainTextToken;

        $this->ikuti($tokenOwner, $toko->slug)
            ->assertStatus(422)->assertJsonPath('errors.store.0', FollowService::PESAN_TOKO_SENDIRI);

        $blokir = $this->tokoBaru();
        $blokir->forceFill(['status' => 'suspended'])->save();

        $tutup = $this->tokoBaru();
        $tutup->forceFill(['status' => 'closed'])->save();

        $this->ikuti($token, $blokir->slug)->assertStatus(404);
        $this->ikuti($token, $tutup->slug)->assertStatus(404);
        $this->ikuti($token, 'tidak-ada')->assertStatus(404);

        $this->assertSame(0, StoreFollower::count());
        $this->assertSame(0, (int) Store::find($toko->id)->followers_count);
    }

    public function test_berhenti_mengikuti_selalu_berhasil_dan_jumlah_pengikut_dikoreksi_dari_tabel(): void
    {
        $toko = $this->tokoBaru();

        [, $t1] = $this->pembeli();
        [, $t2] = $this->pembeli();

        $this->ikuti($t1, $toko->slug)->assertStatus(201);
        $this->ikuti($t2, $toko->slug)->assertStatus(201);

        // Angka tersimpan sempat melenceng: dikoreksi dari tabel pengikut saat berhenti mengikuti.
        DB::table('stores')->where('id', $toko->id)->update(['followers_count' => 99]);

        $this->henti($t1, $toko->slug)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Anda berhenti mengikuti toko ini.'])
            ->assertJsonPath('data.following', false)
            ->assertJsonPath('data.followers_count', 1);

        $this->assertSame(1, (int) Store::find($toko->id)->followers_count);

        // Diulang dan belum pernah mengikuti: tetap 200.
        $this->henti($t1, $toko->slug)->assertOk()->assertJsonPath('data.followers_count', 1);

        // Toko yang sudah ditangguhkan tetap bisa ditinggalkan, dan toko yang tidak ada dijawab 404.
        $toko->forceFill(['status' => 'suspended'])->save();

        $this->henti($t2, $toko->slug)->assertOk()->assertJsonPath('data.followers_count', 0);
        $this->henti($t2, 'tidak-ada')->assertStatus(404);

        $this->assertSame(0, StoreFollower::count());
        $this->assertSame(0, (int) Store::find($toko->id)->followers_count);
    }

    public function test_daftar_toko_yang_diikuti_hanya_menampilkan_toko_aktif_terbaru_dulu(): void
    {
        [$user, $token]     = $this->pembeli();
        [$lain, $tokenLain] = $this->pembeli();

        $a = $this->tokoBaru();
        $b = $this->tokoBaru();
        $c = $this->tokoBaru();

        foreach ([$a, $b, $c] as $t) {
            StoreFollower::create(['store_id' => $t->id, 'user_id' => $user->id]);
        }

        StoreFollower::create(['store_id' => $a->id, 'user_id' => $lain->id]);

        $c->forceFill(['status' => 'suspended'])->save();

        $res = $this->kirim('GET', '/api/v1/following', [], $token)->assertOk()->assertJson(['success' => true]);

        // Toko yang ditangguhkan disembunyikan, yang terakhir diikuti tampil lebih dulu.
        $this->assertSame([$b->slug, $a->slug], $this->slug($res));
        $this->assertSame(2, $res->json('meta.total'));

        $kartu = $res->json('data.1');

        $this->assertSame([$a->uuid, $a->name, 'Sleman', null], [$kartu['id'], $kartu['name'], $kartu['city'], $kartu['logo_url']]);
        $this->assertNotNull($kartu['followed_at']);
        $this->assertArrayHasKey('rating_avg', $kartu);
        $this->assertArrayHasKey('followers_count', $kartu);
        $this->assertStringNotContainsString('@nexora.test', $res->getContent());

        // Paginasi.
        $this->assertSame([$a->slug], $this->slug($this->kirim('GET', '/api/v1/following?per_page=1&page=2', [], $token)->assertOk()));

        foreach (['?per_page=100', '?per_page=0', '?page=0'] as $salah) {
            $this->kirim('GET', "/api/v1/following{$salah}", [], $token)->assertStatus(422);
        }

        // Pengguna lain hanya melihat miliknya.
        $this->assertSame([$a->slug], $this->slug($this->kirim('GET', '/api/v1/following', [], $tokenLain)->assertOk()));

        // Toko yang diaktifkan lagi muncul kembali.
        $c->forceFill(['status' => 'active'])->save();

        $this->assertSame([$c->slug, $b->slug, $a->slug], $this->slug($this->kirim('GET', '/api/v1/following', [], $token)->assertOk()));
    }

    public function test_seller_melihat_jumlah_dan_daftar_pengikutnya_dengan_nama_tersamar(): void
    {
        $toko  = $this->tokoBaru();
        $token = $this->tokenToko($toko);

        $lain      = $this->tokoBaru();
        $tokenLain = $this->tokenToko($lain);

        [$u1] = $this->pembeli();
        [$u2] = $this->pembeli();
        $u1->forceFill(['name' => 'Budi Santoso'])->save();
        $u2->forceFill(['name' => 'Citra Dewi'])->save();

        StoreFollower::create(['store_id' => $toko->id, 'user_id' => $u1->id]);
        StoreFollower::create(['store_id' => $toko->id, 'user_id' => $u2->id]);

        $res = $this->kirim('GET', '/api/v1/seller/followers', [], $token)
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('summary.followers_count', 2)
            ->assertJsonPath('meta.total', 2);

        // Terbaru lebih dulu, nama tersamar, tanpa data pribadi.
        $this->assertSame(['C*** D***', 'B*** S***'], collect($res->json('data'))->pluck('name')->all());
        $this->assertNotNull($res->json('data.0.followed_at'));

        foreach (['Budi', 'Citra', '@nexora.test', 'user_id'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $res->getContent());
        }

        // Paginasi dan masukan tidak valid.
        $this->assertSame(['B*** S***'], collect($this->kirim('GET', '/api/v1/seller/followers?per_page=1&page=2', [], $token)->assertOk()->json('data'))->pluck('name')->all());

        $this->kirim('GET', '/api/v1/seller/followers?per_page=0', [], $token)->assertStatus(422);

        // Seller lain tidak melihat pengikut toko ini.
        $this->kirim('GET', '/api/v1/seller/followers', [], $tokenLain)
            ->assertOk()->assertJsonPath('summary.followers_count', 0)->assertJsonPath('data', []);
    }
}
