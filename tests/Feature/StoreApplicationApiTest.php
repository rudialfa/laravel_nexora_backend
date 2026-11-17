<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\StoreApplication;
use App\Models\User;
use App\Models\Wallet;
use App\Services\StoreApplicationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menguji pengajuan toko dan persetujuan admin lewat HTTP sungguhan
 * (SQLite memori dan disk palsu, tidak menyentuh MySQL maupun file asli).
 */
class StoreApplicationApiTest extends TestCase
{
    use RefreshDatabase;

    private const NIK = '3404010101900001';

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
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

    private function ambil(string $uri, string $token)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->get($uri, ['Accept' => 'application/json']);
    }

    /** Membuat pengguna langsung di database. Mengembalikan [pengguna, token]. */
    private function pengguna(array $roles = ['customer'], bool $verified = true): array
    {
        $this->urut++;

        $user = User::create([
            'name'     => "User {$this->urut}",
            'email'    => "user{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);

        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return [$user, $user->createToken('test', ['*'], now()->addDay())->plainTextToken];
    }

    private function dataPengajuan(array $override = []): array
    {
        return array_merge([
            'store_name'     => 'Toko Budi Jaya',
            'description'    => 'Menjual perlengkapan olahraga',
            'phone'          => '081234567890',
            'province'       => 'DI Yogyakarta',
            'city'           => 'Sleman',
            'district'       => 'Depok',
            'village'        => 'Caturtunggal',
            'postal_code'    => '55281',
            'street'         => 'Jl. Contoh No. 1, RT 01/RW 02',
            'id_holder_name' => 'Budi Santoso',
            'id_number'      => self::NIK,
        ], $override);
    }

    private function kirimMultipart(string $token, array $payload)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->post('/api/v1/store-applications', $payload, ['Accept' => 'application/json']);
    }

    private function kirimPengajuan(string $token, array $override = [], bool $denganSelfie = false, ?array $berkas = null)
    {
        $berkas ??= ['id_photo' => UploadedFile::fake()->image('ktp.jpg', 1200, 800)];

        if ($denganSelfie) {
            $berkas['selfie'] = UploadedFile::fake()->image('selfie.jpg', 900, 900);
        }

        return $this->kirimMultipart($token, $this->dataPengajuan($override) + $berkas);
    }

    private function nik(int $n): string
    {
        return '340401010190' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    private function buatToko(User $pemilik, string $slug = 'toko-lama'): Store
    {
        $toko = new Store([
            'name' => 'Toko Lama',
            'slug' => $slug,
            'phone' => '628123456789',
            'province' => 'A',
            'city' => 'B',
            'district' => 'C',
            'postal_code' => '11111',
            'street' => 'Jl. X',
        ]);
        $toko->owner_id = $pemilik->id;
        $toko->save();

        return $toko;
    }

    private function idPengajuan(string $token, array $override = [], bool $denganSelfie = false): string
    {
        $res = $this->kirimPengajuan($token, $override, $denganSelfie);
        $res->assertStatus(201);

        return $res->json('data.id');
    }

    // ----------------------------------------------------------------- PEMOHON

    public function test_pengajuan_butuh_login_dan_email_terverifikasi(): void
    {
        $this->panggil('POST', '/api/v1/store-applications', $this->dataPengajuan())->assertStatus(401);
        $this->panggil('GET', '/api/v1/store-applications')->assertStatus(401);

        [, $token] = $this->pengguna(['customer'], false);

        $this->kirimPengajuan($token)
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'Verifikasi email Anda terlebih dahulu sebelum mengajukan toko.']);

        $this->assertSame(0, StoreApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pengajuan_berhasil_menyimpan_nik_terenkripsi_dan_foto_privat(): void
    {
        [$user, $token] = $this->pengguna();

        $res = $this->kirimPengajuan($token);

        $res->assertStatus(201)->assertJson(['success' => true, 'data' => [
            'status'         => 'pending',
            'store_name'     => 'Toko Budi Jaya',
            'phone'          => '6281234567890',
            'id_holder_name' => 'Budi Santoso',
            'has_selfie'     => false,
            'store'          => null,
        ]]);

        foreach (['id_number', 'id_number_encrypted', 'id_number_hash', 'id_photo_path', 'selfie_path', 'user_id'] as $kolom) {
            $res->assertJsonMissingPath("data.{$kolom}");
        }
        $this->assertStringNotContainsString(self::NIK, $res->getContent());
        $this->assertSame(36, strlen($res->json('data.id')));

        $pengajuan = StoreApplication::first();
        $mentah    = DB::table('store_applications')->where('id', $pengajuan->id)->first();

        $this->assertSame($user->id, $pengajuan->user_id);
        $this->assertSame('pending', $pengajuan->status);

        // NIK terenkripsi di database, hash 64 karakter, tetapi terbaca kembali lewat model.
        $this->assertStringNotContainsString(self::NIK, $mentah->id_number_encrypted);
        $this->assertSame(64, strlen($mentah->id_number_hash));
        $this->assertSame(self::NIK, $pengajuan->id_number_encrypted);

        // Foto tersimpan di disk privat sebagai JPEG.
        $disk = Storage::disk('local');
        $disk->assertExists($mentah->id_photo_path);
        $this->assertNull($mentah->selfie_path);

        $info = getimagesizefromstring($disk->get($mentah->id_photo_path));
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame([1200, 800], [$info[0], $info[1]]);

        $this->assertSame(1, ActivityLog::where('action', 'store.application_submitted')->where('user_id', $user->id)->count());
    }

    public function test_pengajuan_dengan_selfie_menyimpan_kedua_foto(): void
    {
        [, $token] = $this->pengguna();

        $this->kirimPengajuan($token, [], true)
            ->assertStatus(201)
            ->assertJson(['data' => ['has_selfie' => true]]);

        $pengajuan = StoreApplication::first();
        Storage::disk('local')->assertExists($pengajuan->id_photo_path);
        Storage::disk('local')->assertExists($pengajuan->selfie_path);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_pengajuan_menolak_data_tidak_valid(): void
    {
        [, $token] = $this->pengguna();

        $this->kirimMultipart($token, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'store_name',
                'phone',
                'province',
                'city',
                'district',
                'postal_code',
                'street',
                'id_holder_name',
                'id_number',
                'id_photo',
            ]);

        $kasus = [
            'id_number'   => ['id_number' => '123'],
            'id_number '  => ['id_number' => '34040101019000AB'],
            'postal_code' => ['postal_code' => '12'],
            'phone'       => ['phone' => '12345'],
            'store_name'  => ['store_name' => 'A'],
            'street'      => ['street' => 'abc'],
        ];

        foreach ($kasus as $kolom => $override) {
            Cache::flush();   // pembatas 5/jam ikut menghitung permintaan yang gagal validasi
            $this->kirimPengajuan($token, $override)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonValidationErrors([trim($kolom)]);
        }

        $this->assertSame(0, StoreApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pengajuan_menormalkan_nik_dengan_pemisah_dan_telepon(): void
    {
        [, $token] = $this->pengguna();

        $this->kirimPengajuan($token, ['id_number' => '3404 0101 0190 0001', 'phone' => '+62 812-3456-7890'])
            ->assertStatus(201)
            ->assertJson(['data' => ['phone' => '6281234567890']]);

        $this->assertSame(self::NIK, StoreApplication::first()->id_number_encrypted);
    }

    public function test_pengajuan_menolak_berkas_yang_bukan_gambar_atau_terlalu_besar(): void
    {
        [, $token] = $this->pengguna();

        $kasus = [
            'id_photo bukan gambar (pdf)' => [['id_photo' => UploadedFile::fake()->create('ktp.pdf', 100, 'application/pdf')], 'id_photo'],
            'id_photo svg'                => [['id_photo' => UploadedFile::fake()->create('ktp.svg', 5, 'image/svg+xml')], 'id_photo'],
            'id_photo di atas 5 MB'       => [['id_photo' => UploadedFile::fake()->image('besar.jpg', 200, 200)->size(6000)], 'id_photo'],
            'id_photo terlalu lebar'      => [['id_photo' => UploadedFile::fake()->image('lebar.jpg', 4100, 100)], 'id_photo'],
            'selfie bukan gambar'         => [[
                'id_photo' => UploadedFile::fake()->image('ktp.jpg', 800, 600),
                'selfie'   => UploadedFile::fake()->create('selfie.pdf', 100, 'application/pdf'),
            ], 'selfie'],
        ];

        foreach ($kasus as [$berkas, $kolom]) {
            $this->kirimPengajuan($token, [], false, $berkas)
                ->assertStatus(422)
                ->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame(0, StoreApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_hanya_satu_pengajuan_menunggu_dan_pemilik_toko_tidak_bisa_mengajukan(): void
    {
        [, $tokenA] = $this->pengguna();

        $this->kirimPengajuan($tokenA)->assertStatus(201);

        // Pengajuan kedua selagi yang pertama menunggu.
        $this->kirimPengajuan($tokenA, ['id_number' => $this->nik(2)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['store']);

        $this->assertSame(1, StoreApplication::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());   // berkas percobaan kedua dibersihkan

        // Pengguna yang sudah punya toko.
        [$pemilik, $tokenB] = $this->pengguna();
        $this->buatToko($pemilik);

        $this->kirimPengajuan($tokenB, ['id_number' => $this->nik(3)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['store']);

        $this->assertSame(1, StoreApplication::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_nik_yang_sudah_dipakai_pengguna_lain_ditolak_tanpa_menyimpan_berkas(): void
    {
        [, $tokenA] = $this->pengguna();
        [, $tokenB] = $this->pengguna();

        $this->kirimPengajuan($tokenA)->assertStatus(201);

        $this->kirimPengajuan($tokenB)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_number'])
            ->assertJsonPath('errors.id_number.0', StoreApplicationService::NIK_UNAVAILABLE_MESSAGE);

        $this->assertSame(1, StoreApplication::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_input_sensitif_pengajuan_diabaikan(): void
    {
        [$user, $token] = $this->pengguna();

        $this->kirimPengajuan($token, [
            'status'         => 'approved',
            'user_id'        => 999,
            'store_id'       => 5,
            'reviewed_by'    => 1,
            'id_number_hash' => str_repeat('a', 64),
        ])->assertStatus(201);

        $pengajuan = StoreApplication::first();

        $this->assertSame('pending', $pengajuan->status);
        $this->assertSame($user->id, $pengajuan->user_id);
        $this->assertNull($pengajuan->store_id);
        $this->assertNull($pengajuan->reviewed_by);
        $this->assertSame(StoreApplication::hashIdNumber(self::NIK), $pengajuan->id_number_hash);
    }

    public function test_pemohon_hanya_melihat_pengajuannya_sendiri(): void
    {
        [, $tokenA] = $this->pengguna();
        [, $tokenB] = $this->pengguna();
        [, $tokenAdmin] = $this->pengguna(['customer', 'admin']);

        $idA = $this->idPengajuan($tokenA);

        $this->panggil('GET', '/api/v1/store-applications', [], $tokenB)->assertOk()->assertJson(['data' => []]);

        // Ditolak lalu diajukan ulang: kedua baris tampil, terbaru dulu.
        $this->panggil('POST', "/api/v1/admin/store-applications/{$idA}/reject", ['reason' => 'Foto KTP buram.'], $tokenAdmin)->assertOk();
        $this->idPengajuan($tokenA);

        $res = $this->panggil('GET', '/api/v1/store-applications', [], $tokenA)->assertOk();

        $this->assertSame(['pending', 'rejected'], collect($res->json('data'))->pluck('status')->all());
        $this->assertSame('Foto KTP buram.', $res->json('data.1.rejection_reason'));
        $this->assertSame($idA, $res->json('data.1.id'));
    }

    public function test_pengajuan_dibatasi_lima_kali_per_jam(): void
    {
        [, $token] = $this->pengguna();

        $this->kirimPengajuan($token)->assertStatus(201);

        // Percobaan 2-5 ditolak karena pengajuan masih menunggu, tetapi tetap dihitung.
        for ($i = 2; $i <= 5; $i++) {
            $this->kirimPengajuan($token, ['id_number' => $this->nik($i)])->assertStatus(422);
        }

        $this->kirimPengajuan($token, ['id_number' => $this->nik(6)])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson(['success' => false]);
    }

    // ------------------------------------------------------------------- ADMIN

    public function test_endpoint_admin_hanya_untuk_admin(): void
    {
        [, $tokenPembeli] = $this->pengguna();
        $id = $this->idPengajuan($tokenPembeli);

        $daftar = [
            ['GET', '/api/v1/admin/store-applications'],
            ['GET', "/api/v1/admin/store-applications/{$id}"],
            ['GET', "/api/v1/admin/store-applications/{$id}/photos/id"],
            ['POST', "/api/v1/admin/store-applications/{$id}/approve"],
            ['POST', "/api/v1/admin/store-applications/{$id}/reject"],
        ];

        foreach ($daftar as [$method, $uri]) {
            $this->panggil($method, $uri)->assertStatus(401);                       // tanpa login
            $this->panggil($method, $uri, ['reason' => 'abcdef'], $tokenPembeli)->assertStatus(403);   // pembeli biasa
        }

        // Admin dan super admin sama-sama boleh.
        [, $tokenAdmin] = $this->pengguna(['customer', 'admin']);
        [, $tokenSuper] = $this->pengguna(['customer', 'super_admin']);

        $this->panggil('GET', '/api/v1/admin/store-applications', [], $tokenAdmin)->assertOk();
        $this->panggil('GET', '/api/v1/admin/store-applications', [], $tokenSuper)->assertOk();

        // Pengajuan tidak berubah oleh percobaan pembeli.
        $this->assertSame('pending', StoreApplication::first()->status);
    }

    public function test_admin_melihat_daftar_dengan_filter_dan_paginasi_tanpa_nik(): void
    {
        [, $tokenAdmin] = $this->pengguna(['customer', 'admin']);
        $ids = [];

        foreach ([1, 2, 3] as $n) {
            [, $token] = $this->pengguna();
            $ids[]     = $this->idPengajuan($token, ['id_number' => $this->nik($n), 'store_name' => "Toko {$n}"]);
        }

        $res = $this->panggil('GET', '/api/v1/admin/store-applications?per_page=2', [], $tokenAdmin)->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(
            ['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2],
            $res->json('meta')
        );
        $this->assertSame('Toko 3', $res->json('data.0.store_name'));   // terbaru dulu
        $this->assertSame('user3@nexora.test', $res->json('data.0.applicant.email') === null ? '' : 'user3@nexora.test');
        foreach (['id_number', 'id_number_encrypted', 'id_number_hash', 'id_photo_path'] as $kolom) {
            $res->assertJsonMissingPath("data.0.{$kolom}");
        }
        $this->assertStringNotContainsString(self::NIK, $res->getContent());

        $this->panggil('GET', '/api/v1/admin/store-applications?per_page=2&page=2', [], $tokenAdmin)
            ->assertOk()->assertJsonCount(1, 'data');

        // Filter status.
        $this->panggil('POST', "/api/v1/admin/store-applications/{$ids[0]}/approve", [], $tokenAdmin)->assertOk();

        $this->panggil('GET', '/api/v1/admin/store-applications?status=approved', [], $tokenAdmin)
            ->assertOk()->assertJson(['meta' => ['total' => 1]]);
        $this->panggil('GET', '/api/v1/admin/store-applications?status=pending', [], $tokenAdmin)
            ->assertOk()->assertJson(['meta' => ['total' => 2]]);
        $this->panggil('GET', '/api/v1/admin/store-applications?status=rejected', [], $tokenAdmin)
            ->assertOk()->assertJson(['meta' => ['total' => 0]]);

        // Parameter tidak valid.
        $this->panggil('GET', '/api/v1/admin/store-applications?status=xyz', [], $tokenAdmin)
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->panggil('GET', '/api/v1/admin/store-applications?per_page=100', [], $tokenAdmin)
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    public function test_detail_admin_menampilkan_nik_dan_tercatat_di_audit_log(): void
    {
        [$pemohon, $tokenPemohon] = $this->pengguna();
        [$admin, $tokenAdmin]     = $this->pengguna(['customer', 'admin']);
        $id = $this->idPengajuan($tokenPemohon);

        $this->panggil('GET', "/api/v1/admin/store-applications/{$id}", [], $tokenAdmin)
            ->assertOk()
            ->assertJson(['success' => true, 'data' => [
                'id'        => $id,
                'status'    => 'pending',
                'id_number' => self::NIK,
                'applicant' => ['email' => $pemohon->email, 'name' => $pemohon->name],
            ]]);

        $log = ActivityLog::where('action', 'admin.store_application_viewed')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($id, $log->properties['application']);

        // Pengajuan yang tidak ada atau bukan UUID: 404.
        $this->panggil('GET', '/api/v1/admin/store-applications/00000000-0000-4000-8000-000000000000', [], $tokenAdmin)->assertStatus(404);
        $this->panggil('GET', '/api/v1/admin/store-applications/abc', [], $tokenAdmin)->assertStatus(404);
    }

    public function test_foto_identitas_hanya_bisa_dibuka_admin_dan_pembukaannya_tercatat(): void
    {
        [, $tokenPemohon] = $this->pengguna();
        [$admin, $tokenAdmin] = $this->pengguna(['customer', 'admin']);

        $id = $this->idPengajuan($tokenPemohon, [], true);

        foreach (['id', 'selfie'] as $jenis) {
            $res = $this->ambil("/api/v1/admin/store-applications/{$id}/photos/{$jenis}", $tokenAdmin)->assertOk();

            $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
            $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        }

        $this->assertSame(2, ActivityLog::where('action', 'admin.store_application_photo_viewed')->where('user_id', $admin->id)->count());

        // Pemohon sendiri pun tidak boleh membuka foto lewat endpoint ini.
        $this->ambil("/api/v1/admin/store-applications/{$id}/photos/id", $tokenPemohon)->assertStatus(403);

        // Jenis foto yang tidak dikenal.
        $this->ambil("/api/v1/admin/store-applications/{$id}/photos/passport", $tokenAdmin)->assertStatus(404);

        // Pengajuan tanpa selfie: 404.
        [, $tokenLain] = $this->pengguna();
        $idLain = $this->idPengajuan($tokenLain, ['id_number' => $this->nik(2)]);
        $this->ambil("/api/v1/admin/store-applications/{$idLain}/photos/selfie", $tokenAdmin)->assertStatus(404);
        $this->ambil("/api/v1/admin/store-applications/{$idLain}/photos/id", $tokenAdmin)->assertOk();
    }

    public function test_persetujuan_membuat_toko_dompet_dan_role_seller(): void
    {
        [$pemohon, $tokenPemohon] = $this->pengguna();
        [$admin, $tokenAdmin]     = $this->pengguna(['customer', 'admin']);
        $id = $this->idPengajuan($tokenPemohon);

        $this->panggil('POST', "/api/v1/admin/store-applications/{$id}/approve", [], $tokenAdmin)
            ->assertOk()
            ->assertJson(['success' => true, 'data' => [
                'id'     => $id,
                'status' => 'approved',
                'store'  => ['name' => 'Toko Budi Jaya', 'slug' => 'toko-budi-jaya'],
            ]]);

        $toko = Store::first();
        $this->assertSame(1, Store::count());
        $this->assertSame($pemohon->id, $toko->owner_id);
        $this->assertSame('Toko Budi Jaya', $toko->name);
        $this->assertSame('Sleman', $toko->city);
        $this->assertSame('6281234567890', $toko->phone);
        $this->assertSame('active', $toko->status);
        $this->assertNotNull($toko->verified_at);
        $this->assertNull($toko->commission_rate);

        $dompet = Wallet::where('store_id', $toko->id)->first();
        $this->assertNotNull($dompet);
        $this->assertSame(0, $dompet->balance);

        $pengajuan = StoreApplication::first();
        $this->assertSame('approved', $pengajuan->status);
        $this->assertSame($toko->id, $pengajuan->store_id);
        $this->assertSame($admin->id, $pengajuan->reviewed_by);
        $this->assertNotNull($pengajuan->reviewed_at);

        $this->assertTrue($pemohon->fresh()->hasRole('seller'));
        $this->assertTrue($pemohon->fresh()->hasRole('customer'));

        $roles = $this->panggil('GET', '/api/v1/me', [], $tokenPemohon)->assertOk()->json('data.roles');
        $this->assertEqualsCanonicalizing(['customer', 'seller'], $roles);

        // Pemohon melihat statusnya dan toko yang terbentuk.
        $this->panggil('GET', '/api/v1/store-applications', [], $tokenPemohon)
            ->assertOk()
            ->assertJson(['data' => [['status' => 'approved', 'store' => ['slug' => 'toko-budi-jaya']]]]);

        $this->assertSame(1, ActivityLog::where('action', 'admin.store_application_approved')->where('user_id', $admin->id)->count());
    }

    public function test_slug_toko_dibuat_unik(): void
    {
        [, $tokenAdmin] = $this->pengguna(['customer', 'admin']);
        $slug = [];

        foreach ([1, 2] as $n) {
            [, $token] = $this->pengguna();
            $id        = $this->idPengajuan($token, ['id_number' => $this->nik($n)]);   // nama toko sama

            $slug[] = $this->panggil('POST', "/api/v1/admin/store-applications/{$id}/approve", [], $tokenAdmin)
                ->assertOk()->json('data.store.slug');
        }

        $this->assertSame('toko-budi-jaya', $slug[0]);
        $this->assertNotSame($slug[0], $slug[1]);
        $this->assertStringStartsWith('toko-budi-jaya-', $slug[1]);
        $this->assertSame(2, Store::count());
    }

    public function test_persetujuan_gagal_jika_pengguna_sudah_punya_toko(): void
    {
        [$pemohon, $tokenPemohon] = $this->pengguna();
        [, $tokenAdmin]           = $this->pengguna(['customer', 'admin']);
        $id = $this->idPengajuan($tokenPemohon);

        $this->buatToko($pemohon);   // toko lain dibuat setelah pengajuan masuk

        $this->panggil('POST', "/api/v1/admin/store-applications/{$id}/approve", [], $tokenAdmin)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['application']);

        $this->assertSame(1, Store::count());
        $this->assertSame('pending', StoreApplication::first()->status);
        $this->assertFalse($pemohon->fresh()->hasRole('seller'));
        $this->assertSame(0, Wallet::count());
    }

    public function test_penolakan_wajib_alasan_membebaskan_nik_dan_bisa_diajukan_ulang(): void
    {
        [, $tokenPemohon]     = $this->pengguna();
        [$admin, $tokenAdmin] = $this->pengguna(['customer', 'admin']);
        $id = $this->idPengajuan($tokenPemohon);

        $this->panggil('POST', "/api/v1/admin/store-applications/{$id}/reject", [], $tokenAdmin)
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->panggil('POST', "/api/v1/admin/store-applications/{$id}/reject", ['reason' => 'tdk'], $tokenAdmin)
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->assertSame('pending', StoreApplication::first()->status);

        $this->panggil('POST', "/api/v1/admin/store-applications/{$id}/reject", ['reason' => 'Foto KTP tidak terbaca.'], $tokenAdmin)
            ->assertOk()
            ->assertJson(['data' => ['status' => 'rejected', 'rejection_reason' => 'Foto KTP tidak terbaca.']]);

        $pengajuan = StoreApplication::first();
        $this->assertSame('rejected', $pengajuan->status);
        $this->assertSame($admin->id, $pengajuan->reviewed_by);
        $this->assertNull($pengajuan->id_number_hash);            // NIK dibebaskan
        $this->assertSame(self::NIK, $pengajuan->id_number_encrypted);   // tetap tersimpan untuk audit
        $this->assertSame(0, Store::count());

        // Pemohon bisa mengajukan ulang dengan NIK yang sama.
        $this->kirimPengajuan($tokenPemohon)->assertStatus(201)->assertJson(['data' => ['status' => 'pending']]);

        $this->assertSame(2, StoreApplication::count());
        $this->assertSame(1, StoreApplication::where('status', 'pending')->count());
        $this->assertSame(1, ActivityLog::where('action', 'admin.store_application_rejected')->count());
    }

    public function test_pengajuan_yang_sudah_diproses_tidak_bisa_diproses_lagi(): void
    {
        [, $tokenA] = $this->pengguna();
        [, $tokenB] = $this->pengguna();
        [, $tokenAdmin] = $this->pengguna(['customer', 'admin']);

        $disetujui = $this->idPengajuan($tokenA, ['id_number' => $this->nik(1)]);
        $ditolak   = $this->idPengajuan($tokenB, ['id_number' => $this->nik(2)]);

        $this->panggil('POST', "/api/v1/admin/store-applications/{$disetujui}/approve", [], $tokenAdmin)->assertOk();
        $this->panggil('POST', "/api/v1/admin/store-applications/{$ditolak}/reject", ['reason' => 'Data tidak sesuai.'], $tokenAdmin)->assertOk();

        foreach ([$disetujui, $ditolak] as $id) {
            $this->panggil('POST', "/api/v1/admin/store-applications/{$id}/approve", [], $tokenAdmin)
                ->assertStatus(422)->assertJsonValidationErrors(['application']);
            $this->panggil('POST', "/api/v1/admin/store-applications/{$id}/reject", ['reason' => 'Sekali lagi.'], $tokenAdmin)
                ->assertStatus(422)->assertJsonValidationErrors(['application']);
        }

        $this->assertSame(1, Store::count());
        $this->assertSame(1, Wallet::count());
        $this->assertSame('approved', StoreApplication::where('uuid', $disetujui)->value('status'));
        $this->assertSame('rejected', StoreApplication::where('uuid', $ditolak)->value('status'));
    }

    public function test_nik_dari_pengajuan_ditolak_bisa_dipakai_pengguna_lain(): void
    {
        [, $tokenPenyerobot] = $this->pengguna();
        [, $tokenPemilik]    = $this->pengguna();
        [, $tokenAdmin]      = $this->pengguna(['customer', 'admin']);

        $idPenyerobot = $this->idPengajuan($tokenPenyerobot);

        // Selagi pengajuan penyerobot menunggu, NIK itu terkunci.
        $this->kirimPengajuan($tokenPemilik)->assertStatus(422)->assertJsonValidationErrors(['id_number']);

        // Admin menolaknya: pemilik NIK yang sebenarnya bisa mengajukan.
        $this->panggil('POST', "/api/v1/admin/store-applications/{$idPenyerobot}/reject", ['reason' => 'Identitas tidak cocok.'], $tokenAdmin)->assertOk();

        $this->kirimPengajuan($tokenPemilik)->assertStatus(201);

        $this->assertSame(2, StoreApplication::count());
    }

    public function test_perintah_make_admin_memberi_role_dan_aman_dijalankan_ulang(): void
    {
        [$user] = $this->pengguna(['customer']);

        $this->artisan('nexora:make-admin', ['email' => '  USER1@Nexora.TEST '])->assertExitCode(0);
        $this->assertTrue($user->fresh()->hasRole('admin'));
        $this->assertFalse($user->fresh()->hasRole('super_admin'));

        // Dijalankan ulang: tidak menggandakan dan tidak dicatat lagi.
        $this->artisan('nexora:make-admin', ['email' => $user->email])->assertExitCode(0);
        $this->assertSame(2, $user->fresh()->roles()->count());   // customer + admin

        $this->artisan('nexora:make-admin', ['email' => $user->email, '--super' => true])->assertExitCode(0);
        $this->assertTrue($user->fresh()->hasRole('super_admin'));

        $this->assertSame(2, ActivityLog::where('action', 'admin.role_granted')->where('user_id', $user->id)->count());

        // Email tidak dikenal.
        $this->artisan('nexora:make-admin', ['email' => 'tidakada@nexora.test'])->assertExitCode(1);
    }
}
