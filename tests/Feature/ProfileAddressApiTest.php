<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menguji profil dan alamat pengiriman lewat HTTP sungguhan (SQLite memori, disk palsu).
 */
class ProfileAddressApiTest extends TestCase
{
    use RefreshDatabase;

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

    private function unggahAvatar(?UploadedFile $file, string $token)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->post(
            '/api/v1/me/avatar',
            $file === null ? [] : ['avatar' => $file],
            ['Accept' => 'application/json']
        );
    }

    private function daftar(string $email = 'budi@nexora.test', string $phone = '081234567890'): string
    {
        $res = $this->panggil('POST', '/api/v1/auth/register', [
            'name'                  => 'Budi Santoso',
            'email'                 => $email,
            'phone'                 => $phone,
            'password'              => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ]);
        $res->assertStatus(201);

        return $res->json('data.token');
    }

    private function login(string $email, string $password)
    {
        return $this->panggil('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function dataAlamat(array $override = []): array
    {
        return array_merge([
            'label'            => 'Rumah',
            'recipient_name'   => 'Budi Santoso',
            'phone'            => '081234567890',
            'province'         => 'DI Yogyakarta',
            'city'             => 'Sleman',
            'district'         => 'Depok',
            'village'          => 'Caturtunggal',
            'postal_code'      => '55281',
            'street'           => 'Jl. Contoh No. 1, RT 01/RW 02',
            'latitude'         => -7.7712,
            'longitude'        => 110.3773,
            'biteship_area_id' => 'area-contoh-1',
        ], $override);
    }

    private function tambahAlamat(string $token, array $override = [])
    {
        return $this->panggil('POST', '/api/v1/addresses', $this->dataAlamat($override), $token);
    }

    // ------------------------------------------------------------------- PROFIL

    public function test_profil_bisa_diubah_sebagian_dan_email_status_role_tidak_ikut_berubah(): void
    {
        $token = $this->daftar();

        $this->panggil('PATCH', '/api/v1/me', [
            'name'       => 'Budi S.',
            'gender'     => 'male',
            'birth_date' => '1995-04-12',
            'email'      => 'hacker@nexora.test',
            'status'     => 'banned',
            'roles'      => ['super_admin'],
        ], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'data' => [
                'name'       => 'Budi S.',
                'gender'     => 'male',
                'birth_date' => '1995-04-12',
                'email'      => 'budi@nexora.test',
                'roles'      => ['customer'],
            ]]);

        $user = User::first();
        $this->assertSame('budi@nexora.test', $user->email);
        $this->assertSame('active', $user->status);
        $this->assertSame(['customer'], $user->getRoleNames()->all());
        $this->assertSame(1, ActivityLog::where('action', 'profile.updated')->where('user_id', $user->id)->count());

        // Mengubah sebagian: kolom lain tidak terhapus.
        $this->panggil('PATCH', '/api/v1/me', ['name' => 'Budi Santoso'], $token)
            ->assertOk()
            ->assertJson(['data' => ['name' => 'Budi Santoso', 'gender' => 'male', 'birth_date' => '1995-04-12']]);
    }

    public function test_ganti_telepon_menormalkan_mereset_verifikasi_dan_menolak_nomor_akun_lain(): void
    {
        $token = $this->daftar('a@nexora.test', '081234567890');
        $this->daftar('b@nexora.test', '082233445566');

        User::where('email', 'a@nexora.test')->first()->forceFill(['phone_verified_at' => now()])->save();

        $this->panggil('PATCH', '/api/v1/me', ['phone' => '+62 813-9999-0000'], $token)
            ->assertOk()
            ->assertJson(['data' => ['phone' => '6281399990000', 'phone_verified' => false]]);

        // Milik akun lain: ditolak.
        $this->panggil('PATCH', '/api/v1/me', ['phone' => '0822-3344-5566'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);

        // Nomor milik sendiri: boleh, dan verifikasi tidak direset.
        User::where('email', 'a@nexora.test')->first()->forceFill(['phone_verified_at' => now()])->save();
        $this->panggil('PATCH', '/api/v1/me', ['phone' => '081399990000'], $token)
            ->assertOk()
            ->assertJson(['data' => ['phone' => '6281399990000', 'phone_verified' => true]]);

        // Telepon boleh dikosongkan.
        $this->panggil('PATCH', '/api/v1/me', ['phone' => null], $token)
            ->assertOk()
            ->assertJson(['data' => ['phone' => null, 'phone_verified' => false]]);
    }

    public function test_profil_menolak_data_tidak_valid(): void
    {
        $token = $this->daftar();

        $kasus = [
            'name'       => ['name' => 'A'],
            'name '      => ['name' => ''],
            'gender'     => ['gender' => 'x'],
            'birth_date' => ['birth_date' => '2999-01-01'],
            'birth_date ' => ['birth_date' => '31-12-1990'],
            'phone'      => ['phone' => '12345'],
        ];

        foreach ($kasus as $kolom => $data) {
            $this->panggil('PATCH', '/api/v1/me', $data, $token)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonValidationErrors([trim($kolom)]);
        }

        $this->assertSame('Budi Santoso', User::first()->name);
    }

    public function test_ganti_password_mengeluarkan_perangkat_lain_tetapi_perangkat_ini_tetap_masuk(): void
    {
        $tokenA = $this->daftar();
        $tokenB = $this->login('budi@nexora.test', 'Rahasia123')->json('data.token');

        $this->panggil('PUT', '/api/v1/me/password', [
            'current_password'      => 'Rahasia123',
            'password'              => 'PasswordBaru456',
            'password_confirmation' => 'PasswordBaru456',
        ], $tokenA)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->panggil('GET', '/api/v1/me', [], $tokenA)->assertOk();
        $this->panggil('GET', '/api/v1/me', [], $tokenB)->assertStatus(401);

        $this->login('budi@nexora.test', 'Rahasia123')->assertStatus(401);
        $this->login('budi@nexora.test', 'PasswordBaru456')->assertOk();

        $this->assertSame(1, ActivityLog::where('action', 'auth.password_changed')->count());
    }

    public function test_ganti_password_menolak_password_lama_salah_sama_lemah_atau_tidak_cocok(): void
    {
        $token = $this->daftar();

        // 1. Password lama salah.
        $this->panggil('PUT', '/api/v1/me/password', [
            'current_password' => 'SalahBanget1',
            'password' => 'PasswordBaru456',
            'password_confirmation' => 'PasswordBaru456',
        ], $token)->assertStatus(422)->assertJsonValidationErrors(['current_password']);

        // 2. Password baru sama dengan yang lama.
        $this->panggil('PUT', '/api/v1/me/password', [
            'current_password' => 'Rahasia123',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ], $token)->assertStatus(422)->assertJsonValidationErrors(['password']);

        // 3. Terlalu lemah.
        $this->panggil('PUT', '/api/v1/me/password', [
            'current_password' => 'Rahasia123',
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ], $token)->assertStatus(422)->assertJsonValidationErrors(['password']);

        // 4. Konfirmasi tidak cocok.
        $this->panggil('PUT', '/api/v1/me/password', [
            'current_password' => 'Rahasia123',
            'password' => 'PasswordBaru456',
            'password_confirmation' => 'Beda12345',
        ], $token)->assertStatus(422)->assertJsonValidationErrors(['password']);

        // 5. Lebih dari 72 karakter.
        $panjang = str_repeat('a', 72) . '1';
        $this->panggil('PUT', '/api/v1/me/password', [
            'current_password' => 'Rahasia123',
            'password' => $panjang,
            'password_confirmation' => $panjang,
        ], $token)->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->login('budi@nexora.test', 'Rahasia123')->assertOk();
        $this->assertSame(0, ActivityLog::where('action', 'auth.password_changed')->count());
    }

    public function test_ganti_password_dibatasi_lima_percobaan_per_menit(): void
    {
        $token = $this->daftar();

        for ($i = 1; $i <= 5; $i++) {
            $this->panggil('PUT', '/api/v1/me/password', [
                'current_password' => 'SalahBanget1',
                'password' => 'PasswordBaru456',
                'password_confirmation' => 'PasswordBaru456',
            ], $token)->assertStatus(422);
        }

        // Percobaan ke-6 diblokir, walaupun password lamanya benar.
        $this->panggil('PUT', '/api/v1/me/password', [
            'current_password' => 'Rahasia123',
            'password' => 'PasswordBaru456',
            'password_confirmation' => 'PasswordBaru456',
        ], $token)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson(['success' => false]);

        $this->login('budi@nexora.test', 'Rahasia123')->assertOk();   // password tidak berubah
    }

    public function test_foto_profil_diproses_ulang_diganti_dan_dihapus(): void
    {
        Storage::fake('public');
        $token = $this->daftar();
        $disk  = Storage::disk('public');

        // Unggah gambar PNG besar: hasilnya JPEG dengan sisi terpanjang 800.
        $res = $this->unggahAvatar(UploadedFile::fake()->image('foto.png', 1600, 1200), $token);
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertNotEmpty($res->json('data.avatar_url'));

        $pertama = User::first()->avatar_path;
        $this->assertStringStartsWith('avatars/', $pertama);
        $this->assertStringEndsWith('.jpg', $pertama);
        $disk->assertExists($pertama);

        $info = getimagesizefromstring($disk->get($pertama));
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(800, $info[0]);
        $this->assertSame(600, $info[1]);

        // Diganti: file lama terhapus, gambar kecil tidak diperbesar.
        $this->unggahAvatar(UploadedFile::fake()->image('baru.jpg', 300, 300), $token)->assertOk();

        $kedua = User::first()->avatar_path;
        $this->assertNotSame($pertama, $kedua);
        $disk->assertMissing($pertama);
        $disk->assertExists($kedua);
        $info = getimagesizefromstring($disk->get($kedua));
        $this->assertSame([300, 300], [$info[0], $info[1]]);

        // Dihapus.
        $this->panggil('DELETE', '/api/v1/me/avatar', [], $token)
            ->assertOk()
            ->assertJson(['data' => ['avatar_url' => null]]);

        $this->assertNull(User::first()->avatar_path);
        $this->assertSame([], $disk->allFiles());
    }

    public function test_foto_profil_menolak_bukan_gambar_svg_terlalu_besar_atau_terlalu_lebar(): void
    {
        Storage::fake('public');
        $token = $this->daftar();

        $this->unggahAvatar(null, $token)->assertStatus(422)->assertJsonValidationErrors(['avatar']);
        $this->unggahAvatar(UploadedFile::fake()->create('dok.pdf', 100, 'application/pdf'), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['avatar']);
        $this->unggahAvatar(UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml'), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['avatar']);
        $this->unggahAvatar(UploadedFile::fake()->image('besar.jpg', 200, 200)->size(3000), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['avatar']);
        $this->unggahAvatar(UploadedFile::fake()->image('lebar.jpg', 4100, 100), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['avatar']);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull(User::first()->avatar_path);
    }

    public function test_endpoint_profil_dan_alamat_wajib_login_dan_akun_aktif(): void
    {
        $this->panggil('PATCH', '/api/v1/me', ['name' => 'Budi Baru'])->assertStatus(401);
        $this->panggil('PUT', '/api/v1/me/password', [])->assertStatus(401);
        $this->panggil('GET', '/api/v1/addresses')->assertStatus(401);
        $this->panggil('POST', '/api/v1/addresses', $this->dataAlamat())->assertStatus(401);

        $token = $this->daftar();
        User::first()->forceFill(['status' => 'suspended'])->save();

        $this->panggil('PATCH', '/api/v1/me', ['name' => 'Budi Baru'], $token)->assertStatus(403);
        $this->panggil('GET', '/api/v1/addresses', [], $token)->assertStatus(403);
        $this->unggahAvatar(UploadedFile::fake()->image('f.jpg'), $token)->assertStatus(403);
    }

    // ------------------------------------------------------------------- ALAMAT

    public function test_alamat_pertama_otomatis_utama_dan_respons_tidak_membocorkan_id_numerik(): void
    {
        $token = $this->daftar();

        $res = $this->tambahAlamat($token);

        $res->assertStatus(201)->assertJson(['success' => true, 'data' => [
            'label'            => 'Rumah',
            'phone'            => '6281234567890',
            'postal_code'      => '55281',
            'biteship_area_id' => 'area-contoh-1',
            'is_default'       => true,
        ]]);

        $data = $res->json('data');
        $this->assertSame(36, strlen($data['id']));
        $this->assertFalse(is_numeric($data['id']));
        $this->assertEqualsWithDelta(-7.7712, $data['latitude'], 0.00001);
        $this->assertEqualsWithDelta(110.3773, $data['longitude'], 0.00001);
        foreach (['user_id', 'uuid', 'deleted_at'] as $kolom) {
            $res->assertJsonMissingPath("data.{$kolom}");
        }

        // Alamat kedua bukan utama.
        $this->tambahAlamat($token, ['label' => 'Kantor'])
            ->assertStatus(201)
            ->assertJson(['data' => ['is_default' => false]]);

        $this->assertSame(2, Address::count());
        $this->assertSame(1, Address::where('is_default', true)->count());
    }

    public function test_alamat_baru_yang_dijadikan_utama_memindahkan_status_utama(): void
    {
        $token = $this->daftar();

        $this->tambahAlamat($token);
        $this->tambahAlamat($token, ['label' => 'Kantor', 'is_default' => true])
            ->assertStatus(201)
            ->assertJson(['data' => ['is_default' => true]]);

        $this->assertSame(1, Address::where('is_default', true)->count());
        $this->assertSame('Kantor', Address::where('is_default', true)->first()->label);
    }

    public function test_alamat_menolak_data_tidak_valid(): void
    {
        $token = $this->daftar();

        $this->panggil('POST', '/api/v1/addresses', [], $token)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'label',
                'recipient_name',
                'phone',
                'province',
                'city',
                'district',
                'postal_code',
                'street',
            ]);

        $kasus = [
            'postal_code' => ['postal_code' => '123'],
            'phone'       => ['phone' => '12345'],
            'longitude'   => ['longitude' => null],        // latitude terisi, longitude kosong
            'latitude'    => ['latitude' => 100],
            'street'      => ['street' => 'abc'],
        ];

        foreach ($kasus as $kolom => $override) {
            $this->tambahAlamat($token, $override)->assertStatus(422)->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame(0, Address::count());
    }

    public function test_alamat_hanya_bisa_diakses_pemiliknya_dan_orang_lain_mendapat_404(): void
    {
        $tokenA = $this->daftar('a@nexora.test', '081234567890');
        $tokenB = $this->daftar('b@nexora.test', '082233445566');

        $id = $this->tambahAlamat($tokenA)->json('data.id');

        $this->panggil('GET', "/api/v1/addresses/{$id}", [], $tokenB)->assertStatus(404)->assertJson(['success' => false]);
        $this->panggil('PATCH', "/api/v1/addresses/{$id}", ['label' => 'Dibajak'], $tokenB)->assertStatus(404);
        $this->panggil('DELETE', "/api/v1/addresses/{$id}", [], $tokenB)->assertStatus(404);
        $this->panggil('POST', "/api/v1/addresses/{$id}/default", [], $tokenB)->assertStatus(404);

        $this->panggil('GET', '/api/v1/addresses', [], $tokenB)->assertOk()->assertJson(['data' => []]);

        // Tidak ada yang berubah, dan pemiliknya tetap bisa mengakses.
        $this->panggil('GET', "/api/v1/addresses/{$id}", [], $tokenA)->assertOk()->assertJson(['data' => ['label' => 'Rumah']]);
        $this->assertSame(1, Address::count());

        // Alamat yang tidak ada atau bukan UUID juga 404.
        $this->panggil('GET', '/api/v1/addresses/00000000-0000-4000-8000-000000000000', [], $tokenA)->assertStatus(404);
        $this->panggil('GET', '/api/v1/addresses/abc', [], $tokenA)->assertStatus(404);
    }

    public function test_alamat_bisa_diubah_sebagian_dan_telepon_dinormalkan(): void
    {
        $token = $this->daftar();
        $id    = $this->tambahAlamat($token)->json('data.id');

        // is_default=false pada alamat utama diabaikan: tetap utama.
        $this->panggil('PATCH', "/api/v1/addresses/{$id}", [
            'street' => 'Jl. Baru No. 9, Sleman',
            'village' => null,
            'is_default' => false,
        ], $token)
            ->assertOk()
            ->assertJson(['data' => [
                'street' => 'Jl. Baru No. 9, Sleman',
                'village' => null,
                'label' => 'Rumah',
                'is_default' => true,
            ]]);

        $this->panggil('PATCH', "/api/v1/addresses/{$id}", ['phone' => '0822-3344-5566'], $token)
            ->assertOk()->assertJson(['data' => ['phone' => '6282233445566']]);

        $this->panggil('PATCH', "/api/v1/addresses/{$id}", ['postal_code' => 'abc'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['postal_code']);

        $this->assertSame('Jl. Baru No. 9, Sleman', Address::first()->street);
    }

    public function test_jadikan_utama_dan_hapus_alamat_utama_menaikkan_alamat_terbaru(): void
    {
        $token = $this->daftar();

        $a = $this->tambahAlamat($token, ['label' => 'A'])->json('data.id');
        $b = $this->tambahAlamat($token, ['label' => 'B'])->json('data.id');
        $c = $this->tambahAlamat($token, ['label' => 'C'])->json('data.id');

        $this->panggil('POST', "/api/v1/addresses/{$c}/default", [], $token)
            ->assertOk()->assertJson(['data' => ['id' => $c, 'is_default' => true]]);

        $this->assertSame(1, Address::where('is_default', true)->count());
        $this->assertTrue(Address::where('uuid', $c)->first()->is_default);

        // Hapus alamat utama (C): alamat terbaru yang tersisa (B) naik menjadi utama.
        $this->panggil('DELETE', "/api/v1/addresses/{$c}", [], $token)->assertOk()->assertJson(['success' => true]);

        $terhapus = Address::withTrashed()->where('uuid', $c)->first();
        $this->assertTrue($terhapus->trashed());
        $this->assertFalse($terhapus->is_default);
        $this->assertTrue(Address::where('uuid', $b)->first()->is_default);
        $this->assertFalse(Address::where('uuid', $a)->first()->is_default);
        $this->assertSame(2, Address::count());

        // Alamat yang sudah dihapus tidak bisa diakses lagi.
        $this->panggil('GET', "/api/v1/addresses/{$c}", [], $token)->assertStatus(404);

        // Hapus semuanya: daftar kosong, alamat berikutnya otomatis utama lagi.
        $this->panggil('DELETE', "/api/v1/addresses/{$b}", [], $token)->assertOk();
        $this->panggil('DELETE', "/api/v1/addresses/{$a}", [], $token)->assertOk();
        $this->panggil('GET', '/api/v1/addresses', [], $token)->assertOk()->assertJson(['data' => []]);

        $this->tambahAlamat($token, ['label' => 'Baru'])->assertStatus(201)->assertJson(['data' => ['is_default' => true]]);
    }

    public function test_alamat_dibatasi_sepuluh_per_akun(): void
    {
        $token = $this->daftar();
        $ids   = [];

        for ($i = 1; $i <= 10; $i++) {
            $res   = $this->tambahAlamat($token, ['label' => "Alamat {$i}"])->assertStatus(201);
            $ids[] = $res->json('data.id');
        }

        $this->tambahAlamat($token, ['label' => 'Alamat 11'])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['address']);

        $this->assertSame(10, Address::count());

        // Setelah satu dihapus, boleh menambah lagi.
        $this->panggil('DELETE', "/api/v1/addresses/{$ids[0]}", [], $token)->assertOk();
        $this->tambahAlamat($token, ['label' => 'Alamat 11'])->assertStatus(201);
        $this->assertSame(10, Address::count());
    }

    public function test_daftar_alamat_mengurutkan_utama_dulu_lalu_terbaru(): void
    {
        $token = $this->daftar();

        $this->tambahAlamat($token, ['label' => 'Rumah']);
        $kantor = $this->tambahAlamat($token, ['label' => 'Kantor'])->json('data.id');
        $this->tambahAlamat($token, ['label' => 'Kos']);

        $this->panggil('POST', "/api/v1/addresses/{$kantor}/default", [], $token)->assertOk();

        $res = $this->panggil('GET', '/api/v1/addresses', [], $token)->assertOk();

        $this->assertSame(['Kantor', 'Kos', 'Rumah'], collect($res->json('data'))->pluck('label')->all());
    }

    public function test_alamat_tidak_bisa_dipindahkan_ke_pengguna_lain_lewat_input(): void
    {
        $tokenA = $this->daftar('a@nexora.test', '081234567890');
        $this->daftar('b@nexora.test', '082233445566');

        $idB = User::where('email', 'b@nexora.test')->value('id');
        $idA = User::where('email', 'a@nexora.test')->value('id');

        $this->tambahAlamat($tokenA, [
            'user_id' => $idB,
            'uuid'    => '00000000-0000-4000-8000-000000000000',
        ])->assertStatus(201);

        $alamat = Address::first();
        $this->assertSame($idA, $alamat->user_id);
        $this->assertNotSame('00000000-0000-4000-8000-000000000000', $alamat->uuid);
        $this->assertSame(0, Address::where('user_id', $idB)->count());
    }
}
