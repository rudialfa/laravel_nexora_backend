<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\PhoneNumber;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Menguji endpoint autentikasi lewat HTTP sungguhan (tanpa MySQL, memakai SQLite memori).
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Mengirim permintaan JSON. Guard autentikasi dan header dibersihkan lebih dulu,
     * karena di dalam satu tes Laravel menyimpan pengguna dan header dari permintaan sebelumnya.
     */
    private function panggil(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->json($method, $uri, $data);
    }

    private function dataRegistrasi(array $override = []): array
    {
        return array_merge([
            'name'                  => 'Budi Santoso',
            'email'                 => 'budi@nexora.test',
            'phone'                 => '081234567890',
            'password'              => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
            'device_name'           => 'Pixel 8',
        ], $override);
    }

    private function daftar(array $override = []): string
    {
        $res = $this->panggil('POST', '/api/v1/auth/register', $this->dataRegistrasi($override));
        $res->assertStatus(201);

        return $res->json('data.token');
    }

    public function test_registrasi_berhasil_membuat_akun_customer_dan_token(): void
    {
        $res = $this->panggil('POST', '/api/v1/auth/register', $this->dataRegistrasi());

        $res->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'phone', 'roles', 'created_at'],
                    'token',
                    'token_type',
                    'expires_at',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data'    => [
                    'token_type' => 'Bearer',
                    'user'       => ['email' => 'budi@nexora.test', 'roles' => ['customer']],
                ],
            ]);

        $this->assertSame(36, strlen($res->json('data.user.id')));
        $this->assertStringNotContainsString('password', $res->getContent());

        $user = User::where('email', 'budi@nexora.test')->first();

        $this->assertTrue($user->hasRole('customer'));
        $this->assertSame(AccountStatus::ACTIVE, $user->status);
        $this->assertTrue(Hash::check('Rahasia123', $user->password));
        $this->assertSame(1, ActivityLog::where('action', 'auth.register')->where('user_id', $user->id)->count());
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('Pixel 8', $user->tokens()->first()->name);
    }

    public function test_registrasi_menormalkan_email_dan_telepon(): void
    {
        $this->panggil('POST', '/api/v1/auth/register', $this->dataRegistrasi([
            'email' => 'Budi@Nexora.TEST',
            'phone' => '+62 812-3456-7890',
        ]))->assertStatus(201);

        $user = User::first();

        $this->assertSame('budi@nexora.test', $user->email);
        $this->assertSame('6281234567890', $user->phone);
    }

    public function test_registrasi_menolak_data_tidak_valid(): void
    {
        $lebihDari72 = str_repeat('a', 72) . '1';

        $kasus = [
            'kosong'                  => [null, ['name', 'email', 'password']],
            'password tanpa angka'    => [['password' => 'RahasiaSaja', 'password_confirmation' => 'RahasiaSaja'], ['password']],
            'password tanpa huruf'    => [['password' => '12345678', 'password_confirmation' => '12345678'], ['password']],
            'password terlalu pendek' => [['password' => 'Ra1', 'password_confirmation' => 'Ra1'], ['password']],
            'password lebih dari 72'  => [['password' => $lebihDari72, 'password_confirmation' => $lebihDari72], ['password']],
            'konfirmasi tidak cocok'  => [['password_confirmation' => 'Beda12345'], ['password']],
            'email salah'             => [['email' => 'bukan-email'], ['email']],
            'telepon salah'           => [['phone' => '12345'], ['phone']],
            'telepon rumah'           => [['phone' => '0211234567'], ['phone']],
            'nama satu huruf'         => [['name' => 'A'], ['name']],
        ];

        foreach ($kasus as $nama => [$override, $kolomError]) {
            $data = $override === null ? [] : $this->dataRegistrasi($override);

            $this->panggil('POST', '/api/v1/auth/register', $data)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonValidationErrors($kolomError);
        }

        $this->assertSame(0, User::count());
    }

    public function test_registrasi_tidak_bisa_menyetel_status_atau_role(): void
    {
        $this->panggil('POST', '/api/v1/auth/register', $this->dataRegistrasi([
            'status'            => 'banned',
            'role'              => 'super_admin',
            'roles'             => ['admin', 'super_admin'],
            'email_verified_at' => now()->toDateTimeString(),
        ]))->assertStatus(201);

        $user = User::first();

        $this->assertSame(AccountStatus::ACTIVE, $user->status);
        $this->assertSame(['customer'], $user->getRoleNames()->all());
        $this->assertNull($user->email_verified_at);
    }

    public function test_registrasi_menolak_email_dan_telepon_ganda(): void
    {
        $this->daftar();

        $this->panggil('POST', '/api/v1/auth/register', $this->dataRegistrasi(['phone' => '082233445566']))
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->panggil('POST', '/api/v1/auth/register', $this->dataRegistrasi(['email' => 'lain@nexora.test']))
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);

        // Nomor yang sama dengan penulisan berbeda tetap terdeteksi ganda.
        $this->panggil('POST', '/api/v1/auth/register', $this->dataRegistrasi([
            'email' => 'lain@nexora.test',
            'phone' => '+62 812-3456-7890',
        ]))->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $this->assertSame(1, User::count());
    }

    public function test_login_berhasil_dan_token_bisa_dipakai(): void
    {
        $this->daftar();

        $res = $this->panggil('POST', '/api/v1/auth/login', [
            'email' => 'budi@nexora.test',
            'password' => 'Rahasia123',
            'device_name' => 'Laptop',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['user' => ['id', 'email', 'roles'], 'token', 'token_type', 'expires_at']])
            ->assertJson(['success' => true]);

        $user = User::where('email', 'budi@nexora.test')->first();

        $this->assertNotNull($user->last_login_at);
        $this->assertSame(1, ActivityLog::where('action', 'auth.login')->where('user_id', $user->id)->count());
        $this->assertSame(2, $user->tokens()->count());   // satu dari registrasi, satu dari login

        $me = $this->panggil('GET', '/api/v1/me', [], $res->json('data.token'));

        $me->assertOk()->assertJson([
            'success' => true,
            'data'    => ['email' => 'budi@nexora.test', 'roles' => ['customer']],
        ]);

        // Kolom internal tidak boleh bocor ke aplikasi.
        foreach (['password', 'remember_token', 'status', 'deleted_at', 'last_login_at'] as $kolom) {
            $me->assertJsonMissingPath("data.{$kolom}");
        }
        $this->assertSame(36, strlen($me->json('data.id')));
    }

    public function test_login_email_tidak_membedakan_huruf_besar_kecil(): void
    {
        $this->daftar();

        $this->panggil('POST', '/api/v1/auth/login', [
            'email' => 'BUDI@NEXORA.TEST',
            'password' => 'Rahasia123',
        ])->assertOk();
    }

    public function test_salah_password_dan_email_tidak_dikenal_dijawab_persis_sama(): void
    {
        $this->daftar();

        $salahPassword = $this->panggil('POST', '/api/v1/auth/login', [
            'email' => 'budi@nexora.test',
            'password' => 'SalahBanget1',
        ]);
        $emailAsing = $this->panggil('POST', '/api/v1/auth/login', [
            'email' => 'tidakada@nexora.test',
            'password' => 'SalahBanget1',
        ]);

        $salahPassword->assertStatus(401)->assertJson(['success' => false, 'message' => 'Email atau password salah.']);
        $emailAsing->assertStatus(401);

        $this->assertSame($salahPassword->json(), $emailAsing->json());

        // Percobaan gagal pada akun yang ada tercatat; pada email asing tidak (tidak mencatat teks sembarang).
        $user = User::first();
        $this->assertSame(1, ActivityLog::where('action', 'auth.login_failed')->count());
        $this->assertSame($user->id, ActivityLog::where('action', 'auth.login_failed')->first()->user_id);
    }

    public function test_akun_nonaktif_ditolak_hanya_jika_password_benar_dan_token_lama_ikut_mati(): void
    {
        $tokenLama = $this->daftar();
        $user      = User::first();

        foreach ([AccountStatus::SUSPENDED, AccountStatus::BANNED] as $status) {
            $user->forceFill(['status' => $status])->save();
            $jumlahToken = $user->tokens()->count();

            // Password benar: 403.
            $this->panggil('POST', '/api/v1/auth/login', [
                'email' => 'budi@nexora.test',
                'password' => 'Rahasia123',
            ])->assertStatus(403)->assertJson(['success' => false]);

            // Password salah: tetap 401, status akun tidak bocor.
            $this->panggil('POST', '/api/v1/auth/login', [
                'email' => 'budi@nexora.test',
                'password' => 'SalahBanget1',
            ])->assertStatus(401);

            // Tidak ada token baru yang diterbitkan.
            $this->assertSame($jumlahToken, $user->tokens()->count());

            // Token yang sudah dipegang tidak berlaku lagi untuk endpoint yang butuh akun aktif.
            $this->panggil('GET', '/api/v1/me', [], $tokenLama)->assertStatus(403);
        }

        // Akun diaktifkan kembali: token lama berfungsi lagi.
        $user->forceFill(['status' => AccountStatus::ACTIVE])->save();
        $this->panggil('GET', '/api/v1/me', [], $tokenLama)->assertOk();
    }

    public function test_akun_yang_dihapus_tidak_bisa_login_dan_tokennya_mati(): void
    {
        $token = $this->daftar();

        User::first()->delete();   // soft delete

        $this->panggil('POST', '/api/v1/auth/login', [
            'email' => 'budi@nexora.test',
            'password' => 'Rahasia123',
        ])->assertStatus(401);

        $this->panggil('GET', '/api/v1/me', [], $token)->assertStatus(401);
    }

    public function test_logout_hanya_mencabut_token_perangkat_ini(): void
    {
        $tokenA = $this->daftar();

        $tokenB = $this->panggil('POST', '/api/v1/auth/login', [
            'email' => 'budi@nexora.test',
            'password' => 'Rahasia123',
            'device_name' => 'Laptop',
        ])->json('data.token');

        $this->panggil('GET', '/api/v1/me', [], $tokenA)->assertOk();

        $this->panggil('POST', '/api/v1/auth/logout', [], $tokenA)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Logout berhasil.']);

        $user = User::first();
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, ActivityLog::where('action', 'auth.logout')->where('user_id', $user->id)->count());

        $this->panggil('GET', '/api/v1/me', [], $tokenA)->assertStatus(401);   // perangkat ini sudah keluar
        $this->panggil('GET', '/api/v1/me', [], $tokenB)->assertOk();          // perangkat lain masih masuk

        // Tanpa token, logout ditolak.
        $this->panggil('POST', '/api/v1/auth/logout')->assertStatus(401);
    }

    public function test_token_punya_masa_berlaku_30_hari_dan_yang_kedaluwarsa_ditolak(): void
    {
        $res = $this->panggil('POST', '/api/v1/auth/register', $this->dataRegistrasi());
        $res->assertStatus(201);

        $this->assertNotNull($res->json('data.expires_at'));

        $token = PersonalAccessToken::first();
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $this->panggil('GET', '/api/v1/me', [], $res->json('data.token'))->assertOk();

        $token->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->panggil('GET', '/api/v1/me', [], $res->json('data.token'))->assertStatus(401);
    }

    public function test_login_dibatasi_setelah_lima_percobaan_gagal(): void
    {
        $this->daftar();

        for ($i = 1; $i <= 5; $i++) {
            $this->panggil('POST', '/api/v1/auth/login', [
                'email' => 'budi@nexora.test',
                'password' => 'SalahBanget1',
            ])->assertStatus(401);
        }

        // Percobaan ke-6 diblokir, walaupun password-nya benar.
        $this->panggil('POST', '/api/v1/auth/login', [
            'email' => 'budi@nexora.test',
            'password' => 'Rahasia123',
        ])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson(['success' => false]);

        // Email lain dari alamat IP yang sama tidak ikut terblokir.
        $this->panggil('POST', '/api/v1/auth/login', [
            'email' => 'orang.lain@nexora.test',
            'password' => 'SalahBanget1',
        ])->assertStatus(401);
    }

    public function test_normalisasi_nomor_telepon(): void
    {
        $valid = [
            '081234567890'       => '6281234567890',
            '0812-3456-7890'     => '6281234567890',
            '+62 812-3456-7890'  => '6281234567890',
            '6281234567890'      => '6281234567890',
            '81234567890'        => '6281234567890',
            '0062 8123456789'    => '628123456789',
            '+62 0812 3456 7890' => '6281234567890',
            '(0812) 3456 7890'   => '6281234567890',
        ];

        foreach ($valid as $masuk => $keluar) {
            $this->assertSame($keluar, PhoneNumber::normalize($masuk), "Gagal untuk {$masuk}");
        }

        foreach (['', '   ', 'abc', '12345', '0211234567', '+1 202 555 0100', '0812'] as $salah) {
            $this->assertNull(PhoneNumber::normalize($salah), "Seharusnya ditolak: '{$salah}'");
        }
    }
}
