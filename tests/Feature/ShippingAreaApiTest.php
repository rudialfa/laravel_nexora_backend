<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Menguji pencarian area pengiriman lewat HTTP (Biteship dipalsukan, tanpa memakai kuota).
 */
class ShippingAreaApiTest extends TestCase
{
    use RefreshDatabase;

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['biteship.key' => 'test-key', 'biteship.base_url' => 'https://api.biteship.com']);
    }

    private function masuk(string $status = 'active'): string
    {
        $this->urut++;

        $user = User::create([
            'name'     => "Pengguna {$this->urut}",
            'email'    => "pengguna{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);

        if ($status !== 'active') {
            $user->forceFill(['status' => $status])->save();
        }

        return $user->createToken('test', ['*'], now()->addDay())->plainTextToken;
    }

    private function cari(string $query, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->getJson('/api/v1/shipping/areas' . $query);
    }

    private function palsuAreaDepok(): void
    {
        Http::fake(['api.biteship.com/v1/maps/areas*' => Http::response(['success' => true, 'areas' => [[
            'id'   => 'IDNP5IDNC111IDND2222IDZ55281',
            'name' => 'Depok, Sleman, DI Yogyakarta. 55281',
            'administrative_division_level_1_name' => 'DI Yogyakarta',
            'administrative_division_level_2_name' => 'Sleman',
            'administrative_division_level_3_name' => 'Depok',
            'postal_code' => 55281,
        ]]])]);
    }

    public function test_pencarian_area_butuh_login_dan_akun_aktif(): void
    {
        Http::fake();

        $this->cari('?q=Sleman')->assertStatus(401);
        $this->cari('?q=Sleman', $this->masuk('suspended'))->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_pencarian_area_menolak_masukan_tidak_valid(): void
    {
        Http::fake();
        $token = $this->masuk();

        foreach (['', '?q=', '?q=ab', '?q=' . str_repeat('a', 101), '?q[]=abc'] as $query) {
            $this->cari($query, $token)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonValidationErrors(['q']);
        }

        Http::assertNothingSent();
    }

    public function test_pencarian_area_mengembalikan_daftar_dan_hasil_sama_dipakai_ulang(): void
    {
        $this->palsuAreaDepok();

        $res = $this->cari('?q=' . urlencode('Depok Sleman'), $this->masuk())
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.0.id', 'IDNP5IDNC111IDND2222IDZ55281')
            ->assertJsonPath('data.0.province', 'DI Yogyakarta')
            ->assertJsonPath('data.0.city', 'Sleman')
            ->assertJsonPath('data.0.district', 'Depok')
            ->assertJsonPath('data.0.postal_code', '55281');

        $this->assertStringNotContainsString('test-key', $res->getContent());

        // Pencarian sama oleh pengguna lain (beda huruf besar dan spasi) tidak memanggil Biteship lagi.
        $this->cari('?q=' . urlencode('depok   sleman'), $this->masuk())
            ->assertOk()
            ->assertJsonPath('data.0.district', 'Depok');

        Http::assertSentCount(1);
    }

    public function test_kegagalan_biteship_dijawab_aman_tanpa_membocorkan_rincian(): void
    {
        $token = $this->masuk();

        Http::fake(['api.biteship.com/v1/maps/areas*' => Http::response(['error' => 'rahasia internal'], 500)]);

        $res = $this->cari('?q=Sleman', $token)
            ->assertStatus(503)
            ->assertJson(['success' => false, 'message' => 'Layanan pengiriman sedang tidak tersedia.']);

        $this->assertStringNotContainsString('rahasia internal', $res->getContent());
        $this->assertStringNotContainsString('test-key', $res->getContent());

        // API key belum diisi: jawaban sama, dan Biteship tidak dipanggil.
        config(['biteship.key' => null]);

        $this->cari('?q=Yogyakarta', $token)
            ->assertStatus(503)
            ->assertJson(['success' => false, 'message' => 'Layanan pengiriman sedang tidak tersedia.']);

        Http::assertSentCount(1);
    }

    public function test_pencarian_area_dibatasi_tiga_puluh_per_menit(): void
    {
        $this->palsuAreaDepok();
        $token = $this->masuk();

        for ($n = 1; $n <= 30; $n++) {
            $this->cari('?q=Sleman', $token)->assertOk();
        }

        $this->cari('?q=Sleman', $token)->assertStatus(429);

        // Pengguna lain tidak ikut terbatasi.
        $this->cari('?q=Sleman', $this->masuk())->assertOk();
    }
}
