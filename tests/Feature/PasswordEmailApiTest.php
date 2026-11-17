<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Menguji lupa/reset password dan verifikasi email lewat HTTP sungguhan.
 * Notifikasi dipalsukan (Notification::fake), jadi tidak ada email yang benar-benar terkirim.
 */
class PasswordEmailApiTest extends TestCase
{
    use RefreshDatabase;

    private const PESAN_LUPA = 'Jika email terdaftar, tautan reset password telah dikirim.';
    private const PESAN_TOKEN = 'Token reset password tidak valid atau sudah kedaluwarsa.';

    protected function setUp(): void
    {
        parent::setUp();

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

    /** Mendaftarkan akun uji dan mengembalikan token login-nya. */
    private function daftar(): string
    {
        $res = $this->panggil('POST', '/api/v1/auth/register', [
            'name'                  => 'Budi Santoso',
            'email'                 => 'budi@nexora.test',
            'phone'                 => '081234567890',
            'password'              => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ]);
        $res->assertStatus(201);

        return $res->json('data.token');
    }

    private function login(string $password)
    {
        return $this->panggil('POST', '/api/v1/auth/login', [
            'email' => 'budi@nexora.test',
            'password' => $password,
        ]);
    }

    /** Meminta reset password lalu mengambil token dari notifikasi yang "terkirim". */
    private function mintaTokenReset(User $user): string
    {
        $this->panggil('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notifikasi) use (&$token) {
            $token = $notifikasi->token;

            return true;
        });

        return $token;
    }

    private function ambilUrlVerifikasi(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function ($notifikasi) use ($user, &$url) {
            $url = $notifikasi->toMail($user)->actionUrl;

            return true;
        });

        return $url;
    }

    private function reset(string $token, string $password = 'PasswordBaru456', ?string $email = null)
    {
        return $this->panggil('POST', '/api/v1/auth/reset-password', [
            'email'                 => $email ?? 'budi@nexora.test',
            'token'                 => $token,
            'password'              => $password,
            'password_confirmation' => $password,
        ]);
    }

    // ------------------------------------------------------------ LUPA PASSWORD

    public function test_lupa_password_dijawab_sama_dan_email_hanya_terkirim_ke_akun_yang_ada(): void
    {
        Notification::fake();
        $this->daftar();
        $user = User::first();

        $asing = $this->panggil('POST', '/api/v1/auth/forgot-password', ['email' => 'tidakada@nexora.test']);
        Notification::assertSentTimes(ResetPassword::class, 0);

        $terdaftar = $this->panggil('POST', '/api/v1/auth/forgot-password', ['email' => 'BUDI@nexora.test']);
        Notification::assertSentTimes(ResetPassword::class, 1);

        $asing->assertOk()->assertJson(['success' => true, 'message' => self::PESAN_LUPA]);
        $this->assertSame($asing->json(), $terdaftar->json());

        // Tautan di email mengarah ke aplikasi pengguna dan memuat token serta email.
        Notification::assertSentTo($user, ResetPassword::class, function ($notifikasi) use ($user) {
            $url = $notifikasi->toMail($user)->actionUrl;

            $this->assertStringStartsWith(
                rtrim(config('nexora.frontend_url'), '/') . '/reset-password?token=',
                $url
            );
            $this->assertStringContainsString('email=' . urlencode('budi@nexora.test'), $url);

            return true;
        });
    }

    public function test_lupa_password_menolak_format_email_salah(): void
    {
        Notification::fake();

        $this->panggil('POST', '/api/v1/auth/forgot-password', ['email' => 'bukan-email'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->panggil('POST', '/api/v1/auth/forgot-password', [])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        Notification::assertNothingSent();
    }

    public function test_lupa_password_dibatasi_lima_kali_per_menit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->panggil('POST', '/api/v1/auth/forgot-password', ['email' => 'siapa@nexora.test'])->assertOk();
        }

        $this->panggil('POST', '/api/v1/auth/forgot-password', ['email' => 'siapa@nexora.test'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson(['success' => false]);
    }

    // ------------------------------------------------------------ RESET PASSWORD

    public function test_reset_password_berhasil_mencabut_semua_token_dan_token_reset_sekali_pakai(): void
    {
        Notification::fake();
        $tokenA = $this->daftar();
        $tokenB = $this->login('Rahasia123')->json('data.token');
        $user   = User::first();

        $tokenReset = $this->mintaTokenReset($user);

        $this->reset($tokenReset)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Password berhasil diubah. Silakan login dengan password baru.']);

        // Semua sesi login di semua perangkat dicabut.
        $this->assertSame(0, $user->tokens()->count());
        $this->panggil('GET', '/api/v1/me', [], $tokenA)->assertStatus(401);
        $this->panggil('GET', '/api/v1/me', [], $tokenB)->assertStatus(401);

        // Password lama tidak berlaku lagi, password baru berlaku.
        $this->login('Rahasia123')->assertStatus(401);
        $this->login('PasswordBaru456')->assertOk();

        // Token reset hanya sekali pakai.
        $this->reset($tokenReset, 'PasswordLain789')->assertStatus(422);
        $this->login('PasswordBaru456')->assertOk();

        $this->assertSame(1, ActivityLog::where('action', 'auth.password_reset')->where('user_id', $user->id)->count());
    }

    public function test_reset_password_gagal_dengan_token_atau_email_salah_dan_pesannya_sama(): void
    {
        $this->daftar();

        $tokenSalah = $this->reset('token-asal-asalan');
        $emailAsing = $this->reset('token-asal-asalan', 'PasswordBaru456', 'tidakada@nexora.test');

        $tokenSalah->assertStatus(422)
            ->assertJson(['success' => false, 'message' => self::PESAN_TOKEN])
            ->assertJsonValidationErrors(['token']);

        $this->assertSame($tokenSalah->json(), $emailAsing->json());

        // Password tidak berubah.
        $this->login('Rahasia123')->assertOk();
    }

    public function test_reset_password_menolak_password_lemah_tanpa_menghabiskan_token(): void
    {
        Notification::fake();
        $this->daftar();
        $user       = User::first();
        $tokenReset = $this->mintaTokenReset($user);   // permintaan ke-1 pada pembatas "password"

        foreach (['abc', 'passwordsaja', '12345678', str_repeat('a', 72) . '1'] as $lemah) {
            $this->reset($tokenReset, $lemah)->assertStatus(422)->assertJsonValidationErrors(['password']);
        }

        // Lupa dan reset password berbagi pembatas 5 permintaan per menit, dan kelimanya
        // sudah terpakai di atas. Penghitung dikosongkan agar pemeriksaan berikutnya tidak
        // terkena 429. Token reset disimpan di database, jadi tidak ikut terhapus.
        Cache::flush();

        $this->panggil('POST', '/api/v1/auth/reset-password', [
            'email' => 'budi@nexora.test',
            'token' => $tokenReset,
            'password' => 'PasswordBaru456',
            'password_confirmation' => 'Beda12345',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        // Password belum berubah dan token masih bisa dipakai dengan password yang benar.
        $this->login('Rahasia123')->assertOk();
        $this->reset($tokenReset)->assertOk();
    }

    public function test_token_reset_kedaluwarsa_setelah_60_menit(): void
    {
        Notification::fake();
        $this->daftar();
        $user       = User::first();
        $tokenReset = $this->mintaTokenReset($user);

        $this->travel(61)->minutes();

        try {
            $this->reset($tokenReset)->assertStatus(422)->assertJson(['message' => self::PESAN_TOKEN]);
        } finally {
            $this->travelBack();
        }

        $this->login('Rahasia123')->assertOk();
    }

    // ----------------------------------------------------------- VERIFIKASI EMAIL

    public function test_registrasi_mengirim_email_verifikasi_dan_akun_belum_terverifikasi(): void
    {
        Notification::fake();
        $token = $this->daftar();
        $user  = User::first();

        Notification::assertSentTimes(VerifyEmail::class, 1);
        Notification::assertSentTo($user, VerifyEmail::class);

        $url = $this->ambilUrlVerifikasi($user);
        $this->assertStringContainsString($user->uuid, $url);          // UUID, bukan nomor urut
        $this->assertStringContainsString('signature=', $url);

        $this->assertNull($user->email_verified_at);
        $this->panggil('GET', '/api/v1/me', [], $token)->assertOk()->assertJson(['data' => ['email_verified' => false]]);
    }

    public function test_registrasi_tetap_berhasil_walau_pengiriman_email_gagal(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('send')->andThrow(new RuntimeException('SMTP sedang down'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $res = $this->panggil('POST', '/api/v1/auth/register', [
            'name'                  => 'Budi Santoso',
            'email'                 => 'budi@nexora.test',
            'password'              => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ]);

        $res->assertStatus(201)->assertJson(['success' => true]);
        $this->assertNotEmpty($res->json('data.token'));
        $this->assertSame(1, User::count());
    }

    public function test_kirim_ulang_verifikasi_butuh_login_dan_dibatasi_tiga_kali_per_menit(): void
    {
        Notification::fake();
        $token = $this->daftar();

        $this->panggil('POST', '/api/v1/auth/email/verification-notification')->assertStatus(401);

        for ($i = 1; $i <= 3; $i++) {
            $this->panggil('POST', '/api/v1/auth/email/verification-notification', [], $token)
                ->assertOk()
                ->assertJson(['success' => true, 'message' => 'Email verifikasi telah dikirim.']);
        }

        $this->panggil('POST', '/api/v1/auth/email/verification-notification', [], $token)
            ->assertStatus(429)->assertHeader('Retry-After');

        Notification::assertSentTimes(VerifyEmail::class, 4);   // 1 dari registrasi + 3 kirim ulang
    }

    public function test_kirim_ulang_tidak_mengirim_apa_pun_jika_sudah_terverifikasi(): void
    {
        Notification::fake();
        $token = $this->daftar();
        User::first()->markEmailAsVerified();

        $this->panggil('POST', '/api/v1/auth/email/verification-notification', [], $token)
            ->assertOk()
            ->assertJson(['message' => 'Email sudah terverifikasi.']);

        Notification::assertSentTimes(VerifyEmail::class, 1);   // hanya yang dari registrasi
    }

    public function test_tautan_verifikasi_memverifikasi_email_dan_aman_dipakai_ulang(): void
    {
        Notification::fake();
        $token = $this->daftar();
        $user  = User::first();
        $url   = $this->ambilUrlVerifikasi($user);

        $this->panggil('GET', $url)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Email berhasil diverifikasi.']);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->panggil('GET', '/api/v1/me', [], $token)->assertOk()->assertJson(['data' => ['email_verified' => true]]);

        // Dibuka dua kali: tetap 200 dan tidak tercatat dua kali.
        $this->panggil('GET', $url)
            ->assertOk()
            ->assertJson(['message' => 'Email sudah terverifikasi.']);

        $this->assertSame(1, ActivityLog::where('action', 'auth.email_verified')->count());
    }

    public function test_tautan_verifikasi_yang_dimanipulasi_atau_kedaluwarsa_ditolak(): void
    {
        Notification::fake();
        $this->daftar();
        $user = User::first();
        $url  = $this->ambilUrlVerifikasi($user);

        // Hash diubah penyerang: tanda tangan tidak cocok.
        $dimanipulasi = preg_replace('#/[a-f0-9]{40}\?#', '/' . str_repeat('a', 40) . '?', $url);
        $this->assertNotSame($url, $dimanipulasi);
        $this->panggil('GET', $dimanipulasi)->assertStatus(403)->assertJson(['success' => false]);

        // Tanda tangan dibuang.
        $this->panggil('GET', strtok($url, '?'))->assertStatus(403);

        // Masa berlaku (60 menit) habis.
        $this->travel(61)->minutes();

        try {
            $this->panggil('GET', $url)->assertStatus(403);
        } finally {
            $this->travelBack();
        }

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_tautan_verifikasi_tidak_berlaku_setelah_email_diganti(): void
    {
        Notification::fake();
        $this->daftar();
        $user = User::first();
        $url  = $this->ambilUrlVerifikasi($user);

        $user->forceFill(['email' => 'baru@nexora.test'])->save();

        // Tanda tangan masih sah, tetapi tautan itu dibuat untuk email yang lama.
        $this->panggil('GET', $url)
            ->assertStatus(403)
            ->assertJson(['message' => 'Tautan verifikasi tidak valid.']);

        $this->assertNull($user->fresh()->email_verified_at);
    }
}
