<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\StoreBankAccount;
use App\Models\StoreCourierSetting;
use App\Models\StoreOperatingHour;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menguji pengelolaan toko oleh seller lewat HTTP sungguhan
 * (SQLite memori dan disk palsu, tidak menyentuh MySQL maupun file asli).
 */
class SellerStoreApiTest extends TestCase
{
    use RefreshDatabase;

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
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

    private function kirimGambar(string $type, ?UploadedFile $file, string $token)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->post(
            "/api/v1/seller/store/images/{$type}",
            $file === null ? [] : ['image' => $file],
            ['Accept' => 'application/json']
        );
    }

    /** Membuat pengguna (dan tokonya). Mengembalikan [pengguna, token, toko]. */
    private function penjual(array $roles = ['customer', 'seller'], bool $denganToko = true, string $statusToko = 'active'): array
    {
        $this->urut++;

        $user = User::create([
            'name'     => "User {$this->urut}",
            'email'    => "user{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        $toko = null;

        if ($denganToko) {
            $toko = new Store([
                'name'             => "Toko {$this->urut}",
                'slug'             => "toko-{$this->urut}",
                'phone'            => '628123456789',
                'province'         => 'DI Yogyakarta',
                'city'             => 'Sleman',
                'district'         => 'Depok',
                'postal_code'      => '55281',
                'street'           => 'Jl. Contoh No. 1',
                'biteship_area_id' => 'area-awal',
            ]);
            $toko->owner_id = $user->id;
            $toko->save();

            if ($statusToko !== 'active') {
                $toko->forceFill(['status' => $statusToko])->save();
            }

            $toko = $toko->fresh();
        }

        return [$user, $user->createToken('test', ['*'], now()->addDay())->plainTextToken, $toko];
    }

    private function tambahRekening(string $token, array $override = [])
    {
        return $this->panggil('POST', '/api/v1/seller/store/bank-accounts', array_merge([
            'bank_code'      => 'BCA',
            'bank_name'      => 'Bank Central Asia',
            'account_name'   => 'Budi Santoso',
            'account_number' => '1234-5678-90',
        ], $override), $token);
    }

    // ----------------------------------------------------------- AKSES DAN TAMPILAN

    public function test_endpoint_seller_hanya_untuk_role_seller_dan_wajib_punya_toko(): void
    {
        $this->panggil('GET', '/api/v1/seller/store')->assertStatus(401);

        [, $tokenPembeli] = $this->penjual(['customer'], false);
        $this->panggil('GET', '/api/v1/seller/store', [], $tokenPembeli)->assertStatus(403);
        $this->panggil('PATCH', '/api/v1/seller/store', ['name' => 'Toko Baru'], $tokenPembeli)->assertStatus(403);

        [, $tokenAdmin] = $this->penjual(['customer', 'admin'], false);
        $this->panggil('GET', '/api/v1/seller/store', [], $tokenAdmin)->assertStatus(403);

        // Punya role seller tetapi tokonya tidak ada.
        [, $tokenTanpaToko] = $this->penjual(['customer', 'seller'], false);
        $this->panggil('GET', '/api/v1/seller/store', [], $tokenTanpaToko)
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Toko Anda tidak ditemukan.']);
    }

    public function test_seller_melihat_toko_sendiri_tanpa_data_internal(): void
    {
        [, $token, $toko] = $this->penjual();
        $this->penjual();   // toko lain, tidak boleh bocor

        $res = $this->panggil('GET', '/api/v1/seller/store', [], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'data' => [
                'id'               => $toko->uuid,
                'name'             => $toko->name,
                'slug'             => $toko->slug,
                'status'           => 'active',
                'is_open'          => true,
                'timezone'         => 'Asia/Jakarta',
                'biteship_area_id' => 'area-awal',
                'rating_avg'       => 0,
                'operating_hours'  => [],
                'couriers'         => [],
            ]]);

        $this->assertSame(36, strlen($res->json('data.id')));
        foreach (['owner_id', 'deleted_at', 'commission_rate'] as $kolom) {
            $res->assertJsonMissingPath("data.{$kolom}");
        }
    }

    // -------------------------------------------------------------------- PROFIL

    public function test_profil_toko_diubah_sebagian_dan_kolom_internal_tidak_ikut_berubah(): void
    {
        [$user, $token, $toko] = $this->penjual();

        $this->panggil('PATCH', '/api/v1/seller/store', [
            'name'            => 'Toko Budi Baru',
            'description'     => 'Deskripsi baru',
            'phone'           => '0812-9999-0000',
            // Semua di bawah ini harus diabaikan:
            'status'          => 'suspended',
            'commission_rate' => 0,
            'rating_avg'      => 5,
            'followers_count' => 999,
            'owner_id'        => 999,
            'slug'            => 'slug-curian',
            'verified_at'     => now()->toDateTimeString(),
        ], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'data' => [
                'name'        => 'Toko Budi Baru',
                'description' => 'Deskripsi baru',
                'phone'       => '6281299990000',
                'slug'        => $toko->slug,
                'status'      => 'active',
            ]]);

        $baru = $toko->fresh();

        $this->assertSame($toko->slug, $baru->slug);                 // slug tidak berubah saat nama diganti
        $this->assertSame($user->id, $baru->owner_id);
        $this->assertSame('active', $baru->status);
        $this->assertNull($baru->commission_rate);
        $this->assertSame('0.00', $baru->rating_avg);
        $this->assertSame(0, $baru->followers_count);
        $this->assertNull($baru->verified_at);

        $this->assertSame(1, ActivityLog::where('action', 'store.updated')->where('user_id', $user->id)->count());
    }

    public function test_profil_toko_menolak_data_tidak_valid(): void
    {
        [, $token, $toko] = $this->penjual();

        $kasus = [
            'name'        => [['name' => 'A'], 'name'],
            'phone'       => [['phone' => '123'], 'phone'],
            'postal_code' => [['postal_code' => '12'], 'postal_code'],
            'street'      => [['street' => 'abc'], 'street'],
            'timezone'    => [['timezone' => 'Asia/Tokyo'], 'timezone'],
            'is_open'     => [['is_open' => 'bukan'], 'is_open'],
            'latitude'    => [['latitude' => 100, 'longitude' => 110], 'latitude'],
            'longitude'   => [['latitude' => -7.7], 'longitude'],
        ];

        foreach ($kasus as [$data, $kolom]) {
            $this->panggil('PATCH', '/api/v1/seller/store', $data, $token)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame($toko->name, $toko->fresh()->name);
        $this->assertSame('628123456789', $toko->fresh()->phone);
    }

    public function test_mengubah_alamat_mengosongkan_kode_area_biteship_kecuali_dikirim_bersama(): void
    {
        [, $token, $toko] = $this->penjual();   // area awal: "area-awal", kota: Sleman

        // Bukan alamat: kode area tetap.
        $this->panggil('PATCH', '/api/v1/seller/store', ['name' => 'Toko Lain'], $token)
            ->assertOk()->assertJson(['data' => ['biteship_area_id' => 'area-awal']]);

        // Alamat dikirim tetapi isinya sama: kode area tetap.
        $this->panggil('PATCH', '/api/v1/seller/store', ['city' => 'Sleman', 'street' => 'Jl. Contoh No. 1'], $token)
            ->assertOk()->assertJson(['data' => ['biteship_area_id' => 'area-awal']]);

        // Alamat berubah tanpa kode area baru: kode area dikosongkan.
        $this->panggil('PATCH', '/api/v1/seller/store', ['city' => 'Bantul'], $token)
            ->assertOk()->assertJson(['data' => ['city' => 'Bantul', 'biteship_area_id' => null]]);

        // Kode area diatur sendiri.
        $this->panggil('PATCH', '/api/v1/seller/store', ['biteship_area_id' => 'area-baru'], $token)
            ->assertOk()->assertJson(['data' => ['biteship_area_id' => 'area-baru']]);

        // Alamat berubah bersama kode area baru: kode area baru dipakai.
        $this->panggil('PATCH', '/api/v1/seller/store', ['city' => 'Kulon Progo', 'biteship_area_id' => 'area-kp'], $token)
            ->assertOk()->assertJson(['data' => ['city' => 'Kulon Progo', 'biteship_area_id' => 'area-kp']]);

        // Koordinat berubah dari kosong: kode area dikosongkan.
        $this->panggil('PATCH', '/api/v1/seller/store', ['latitude' => -7.7, 'longitude' => 110.4], $token)
            ->assertOk()->assertJson(['data' => ['latitude' => -7.7, 'longitude' => 110.4, 'biteship_area_id' => null]]);

        // Koordinat dikirim ulang dengan nilai yang sama: kode area tidak disentuh.
        $this->panggil('PATCH', '/api/v1/seller/store', ['biteship_area_id' => 'area-x'], $token)->assertOk();
        $this->panggil('PATCH', '/api/v1/seller/store', ['latitude' => -7.7, 'longitude' => 110.4], $token)
            ->assertOk()->assertJson(['data' => ['biteship_area_id' => 'area-x']]);

        $this->assertSame('area-x', $toko->fresh()->biteship_area_id);
    }

    public function test_buka_tutup_toko_dan_pesan_libur(): void
    {
        [, $token, $toko] = $this->penjual();

        $this->panggil('PATCH', '/api/v1/seller/store', ['is_open' => false, 'closed_note' => 'Libur Lebaran sampai 5 April'], $token)
            ->assertOk()
            ->assertJson(['data' => ['is_open' => false, 'closed_note' => 'Libur Lebaran sampai 5 April']]);

        $this->assertFalse($toko->fresh()->is_open);

        // Dibuka kembali: pesan libur dihapus otomatis.
        $this->panggil('PATCH', '/api/v1/seller/store', ['is_open' => true], $token)
            ->assertOk()
            ->assertJson(['data' => ['is_open' => true, 'closed_note' => null]]);

        // Dibuka kembali dengan pesan baru: pesan baru dipakai.
        $this->panggil('PATCH', '/api/v1/seller/store', ['is_open' => true, 'closed_note' => 'Buka lagi, ada diskon!'], $token)
            ->assertOk()->assertJson(['data' => ['closed_note' => 'Buka lagi, ada diskon!']]);
    }

    public function test_toko_ditangguhkan_atau_ditutup_bisa_dilihat_tetapi_tidak_bisa_diubah(): void
    {
        foreach (['suspended', 'closed'] as $status) {
            [, $token, $toko] = $this->penjual(['customer', 'seller'], true, $status);

            $this->panggil('GET', '/api/v1/seller/store', [], $token)
                ->assertOk()->assertJson(['data' => ['status' => $status]]);
            $this->panggil('GET', '/api/v1/seller/store/bank-accounts', [], $token)->assertOk();

            $perubahan = [
                ['PATCH', '/api/v1/seller/store', ['name' => 'Nama Baru']],
                ['DELETE', '/api/v1/seller/store/images/logo', []],
                ['PUT', '/api/v1/seller/store/operating-hours', ['hours' => [['day_of_week' => 1, 'is_closed' => true]]]],
                ['PUT', '/api/v1/seller/store/couriers', ['couriers' => []]],
            ];

            foreach ($perubahan as [$method, $uri, $data]) {
                $this->panggil($method, $uri, $data, $token)
                    ->assertStatus(403)
                    ->assertJson(['success' => false]);
            }

            $this->kirimGambar('logo', UploadedFile::fake()->image('logo.png', 200, 200), $token)->assertStatus(403);
            $this->tambahRekening($token)->assertStatus(403);

            $this->assertNotSame('Nama Baru', $toko->fresh()->name);
            $this->assertSame(0, StoreBankAccount::where('store_id', $toko->id)->count());
        }
    }

    // ------------------------------------------------------------------- GAMBAR

    public function test_logo_dan_banner_diproses_diganti_dan_dihapus(): void
    {
        [, $token, $toko] = $this->penjual();
        $disk = Storage::disk('public');

        // Logo PNG besar diperkecil menjadi JPEG dengan sisi terpanjang 512.
        $res = $this->kirimGambar('logo', UploadedFile::fake()->image('logo.png', 1000, 1000), $token);
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull($res->json('data.logo_url'));

        $logo1 = $toko->fresh()->logo_path;
        $this->assertStringStartsWith('stores/logos/', $logo1);
        $this->assertStringEndsWith('.jpg', $logo1);
        $info = getimagesizefromstring($disk->get($logo1));
        $this->assertSame([IMAGETYPE_JPEG, 512, 512], [$info[2], $info[0], $info[1]]);

        // Banner: sisi terpanjang 1600.
        $this->kirimGambar('banner', UploadedFile::fake()->image('banner.jpg', 3200, 1000), $token)->assertOk();
        $banner = $toko->fresh()->banner_path;
        $this->assertStringStartsWith('stores/banners/', $banner);
        $info = getimagesizefromstring($disk->get($banner));
        $this->assertSame([1600, 500], [$info[0], $info[1]]);

        // Logo diganti: file lama terhapus, gambar kecil tidak diperbesar, banner tidak tersentuh.
        $this->kirimGambar('logo', UploadedFile::fake()->image('baru.jpg', 300, 300), $token)->assertOk();
        $logo2 = $toko->fresh()->logo_path;
        $this->assertNotSame($logo1, $logo2);
        $disk->assertMissing($logo1);
        $disk->assertExists($logo2);
        $disk->assertExists($banner);
        $info = getimagesizefromstring($disk->get($logo2));
        $this->assertSame([300, 300], [$info[0], $info[1]]);

        // Dihapus satu per satu.
        $this->panggil('DELETE', '/api/v1/seller/store/images/logo', [], $token)
            ->assertOk()->assertJson(['data' => ['logo_url' => null]]);
        $this->assertNull($toko->fresh()->logo_path);
        $this->assertCount(1, $disk->allFiles());

        $this->panggil('DELETE', '/api/v1/seller/store/images/banner', [], $token)
            ->assertOk()->assertJson(['data' => ['banner_url' => null]]);
        $this->assertSame([], $disk->allFiles());
    }

    public function test_gambar_toko_menolak_berkas_tidak_valid(): void
    {
        [, $token, $toko] = $this->penjual();

        $this->kirimGambar('logo', null, $token)->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimGambar('logo', UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimGambar('logo', UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->kirimGambar('logo', UploadedFile::fake()->image('besar.jpg', 200, 200)->size(2500), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);   // logo maksimal 2 MB
        $this->kirimGambar('banner', UploadedFile::fake()->image('lebar.jpg', 4100, 100), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['image']);

        // Jenis gambar yang tidak dikenal.
        $this->kirimGambar('avatar', UploadedFile::fake()->image('x.jpg', 100, 100), $token)->assertStatus(404);

        $this->assertNull($toko->fresh()->logo_path);
        $this->assertNull($toko->fresh()->banner_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ------------------------------------------------------------ JAM OPERASIONAL

    public function test_jadwal_buka_diganti_seluruhnya_dan_tidak_menyentuh_toko_lain(): void
    {
        [, $token, $toko]       = $this->penjual();
        [, $tokenLain, $tokoLain] = $this->penjual();

        $this->panggil('PUT', '/api/v1/seller/store/operating-hours', ['hours' => [
            ['day_of_week' => 5, 'is_closed' => false, 'open_time' => '09:00', 'close_time' => '15:00'],
        ]], $tokenLain)->assertOk();

        $res = $this->panggil('PUT', '/api/v1/seller/store/operating-hours', ['hours' => [
            ['day_of_week' => 6, 'is_closed' => false, 'open_time' => '09:00', 'close_time' => '13:00'],
            ['day_of_week' => 1, 'is_closed' => false, 'open_time' => '08:00', 'close_time' => '17:00'],
            ['day_of_week' => 7, 'is_closed' => true, 'open_time' => '08:00', 'close_time' => '17:00'],   // jam diabaikan
        ]], $token)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([
            ['day_of_week' => 1, 'is_closed' => false, 'open_time' => '08:00', 'close_time' => '17:00'],
            ['day_of_week' => 6, 'is_closed' => false, 'open_time' => '09:00', 'close_time' => '13:00'],
            ['day_of_week' => 7, 'is_closed' => true, 'open_time' => null, 'close_time' => null],
        ], $res->json('data.operating_hours'));

        // Diganti lagi: hanya yang baru yang tersisa.
        $this->panggil('PUT', '/api/v1/seller/store/operating-hours', ['hours' => [
            ['day_of_week' => 2, 'is_closed' => false, 'open_time' => '10:00', 'close_time' => '18:00'],
        ]], $token)->assertOk();

        $this->assertSame(1, StoreOperatingHour::where('store_id', $toko->id)->count());
        $this->panggil('GET', '/api/v1/seller/store', [], $token)
            ->assertOk()->assertJsonCount(1, 'data.operating_hours')
            ->assertJson(['data' => ['operating_hours' => [['day_of_week' => 2, 'open_time' => '10:00']]]]);

        // Jadwal toko lain tetap utuh.
        $this->assertSame(1, StoreOperatingHour::where('store_id', $tokoLain->id)->count());
        $this->assertSame(5, StoreOperatingHour::where('store_id', $tokoLain->id)->value('day_of_week'));
    }

    public function test_jadwal_buka_menolak_data_tidak_valid(): void
    {
        [, $token, $toko] = $this->penjual();

        $buka = fn(int $hari, string $dari = '08:00', string $sampai = '17:00') => [
            'day_of_week' => $hari,
            'is_closed' => false,
            'open_time' => $dari,
            'close_time' => $sampai,
        ];

        $delapan = array_map(fn($n) => $buka(($n % 7) + 1), range(1, 8));

        $kasus = [
            'hari kembar'        => [[$buka(1), $buka(1)], 'hours.0.day_of_week'],
            'hari 8'             => [[$buka(8)], 'hours.0.day_of_week'],
            'hari 0'             => [[$buka(0)], 'hours.0.day_of_week'],
            'tutup sebelum buka' => [[$buka(1, '17:00', '08:00')], 'hours.0.close_time'],
            'jam sama'           => [[$buka(1, '08:00', '08:00')], 'hours.0.close_time'],
            'jam buka kosong'    => [[['day_of_week' => 1, 'is_closed' => false, 'close_time' => '17:00']], 'hours.0.open_time'],
            'jam tutup kosong'   => [[['day_of_week' => 1, 'is_closed' => false, 'open_time' => '08:00']], 'hours.0.close_time'],
            'jam 25:00'          => [[$buka(1, '25:00', '26:00')], 'hours.0.open_time'],
            'menit 60'           => [[$buka(1, '12:60', '13:00')], 'hours.0.open_time'],
            'bukan jam'          => [[$buka(1, 'abc', '17:00')], 'hours.0.open_time'],
            'is_closed hilang'   => [[['day_of_week' => 1, 'open_time' => '08:00', 'close_time' => '17:00']], 'hours.0.is_closed'],
            'daftar kosong'      => [[], 'hours'],
            'lebih dari 7 hari'  => [$delapan, 'hours'],
        ];

        foreach ($kasus as $nama => [$hours, $kolom]) {
            $this->panggil('PUT', '/api/v1/seller/store/operating-hours', ['hours' => $hours], $token)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame(0, StoreOperatingHour::where('store_id', $toko->id)->count());
    }

    // -------------------------------------------------------------------- KURIR

    public function test_kurir_toko_diganti_seluruhnya_dinormalkan_dan_tidak_menyentuh_toko_lain(): void
    {
        [, $token, $toko]         = $this->penjual();
        [, $tokenLain, $tokoLain] = $this->penjual();

        $this->panggil('PUT', '/api/v1/seller/store/couriers', ['couriers' => [
            ['code' => 'jne', 'is_active' => true],
        ]], $tokenLain)->assertOk();

        $res = $this->panggil('PUT', '/api/v1/seller/store/couriers', ['couriers' => [
            ['code' => ' SiCepat ', 'is_active' => false],
            ['code' => 'JNE', 'is_active' => true],
        ]], $token)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([
            ['code' => 'jne', 'is_active' => true],
            ['code' => 'sicepat', 'is_active' => false],
        ], $res->json('data.couriers'));

        $this->assertSame(['jne', 'sicepat'], StoreCourierSetting::where('store_id', $toko->id)->orderBy('courier_code')->pluck('courier_code')->all());

        // Diganti: hanya yang baru tersisa.
        $this->panggil('PUT', '/api/v1/seller/store/couriers', ['couriers' => [
            ['code' => 'anteraja', 'is_active' => true],
        ]], $token)->assertOk()->assertJsonCount(1, 'data.couriers');
        $this->assertSame(1, StoreCourierSetting::where('store_id', $toko->id)->count());

        // Daftar kosong: seluruh pengaturan toko dihapus (memakai kurir bawaan marketplace).
        $this->panggil('PUT', '/api/v1/seller/store/couriers', ['couriers' => []], $token)
            ->assertOk()->assertJson(['data' => ['couriers' => []]]);
        $this->assertSame(0, StoreCourierSetting::where('store_id', $toko->id)->count());

        // Toko lain tidak tersentuh.
        $this->assertSame(1, StoreCourierSetting::where('store_id', $tokoLain->id)->count());
    }

    public function test_kurir_menolak_data_tidak_valid(): void
    {
        [, $token, $toko] = $this->penjual();

        $tigaPuluhSatu = array_map(fn($n) => ['code' => "kurir{$n}", 'is_active' => true], range(1, 31));

        $kasus = [
            'kembar setelah dinormalkan' => [['couriers' => [['code' => 'jne', 'is_active' => true], ['code' => 'JNE', 'is_active' => false]]], 'couriers.1.code'],
            'kode terlalu pendek'        => [['couriers' => [['code' => 'j', 'is_active' => true]]], 'couriers.0.code'],
            'kode ada spasi'             => [['couriers' => [['code' => 'jne reg', 'is_active' => true]]], 'couriers.0.code'],
            'kode kosong'                => [['couriers' => [['is_active' => true]]], 'couriers.0.code'],
            'is_active hilang'           => [['couriers' => [['code' => 'jne']]], 'couriers.0.is_active'],
            'kunci couriers hilang'      => [[], 'couriers'],
            'lebih dari 30'              => [['couriers' => $tigaPuluhSatu], 'couriers'],
        ];

        foreach ($kasus as [$data, $kolom]) {
            $this->panggil('PUT', '/api/v1/seller/store/couriers', $data, $token)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame(0, StoreCourierSetting::where('store_id', $toko->id)->count());
    }

    // ---------------------------------------------------------------- REKENING

    public function test_rekening_pertama_otomatis_utama_nomornya_terenkripsi_dan_tidak_bocor(): void
    {
        [$user, $token, $toko] = $this->penjual();

        $res = $this->tambahRekening($token, ['store_id' => 999, 'is_default' => false]);

        $res->assertStatus(201)->assertJson(['success' => true, 'data' => [
            'bank_code'             => 'bca',
            'bank_name'             => 'Bank Central Asia',
            'account_name'          => 'Budi Santoso',
            'account_number_masked' => '****7890',
            'is_default'            => true,
        ]]);

        $this->assertSame(36, strlen($res->json('data.id')));
        $res->assertJsonMissingPath('data.account_number');
        $res->assertJsonMissingPath('data.account_number_encrypted');
        $this->assertStringNotContainsString('1234567890', $res->getContent());

        // Terenkripsi di database, tetapi terbaca kembali lewat model.
        $mentah = DB::table('store_bank_accounts')->first();
        $this->assertStringNotContainsString('1234567890', $mentah->account_number_encrypted);
        $this->assertSame('7890', $mentah->account_number_last4);
        $this->assertSame($toko->id, (int) $mentah->store_id);
        $this->assertSame('1234567890', StoreBankAccount::first()->account_number_encrypted);

        // Daftar rekening juga tidak membocorkan nomor.
        $daftar = $this->panggil('GET', '/api/v1/seller/store/bank-accounts', [], $token)->assertOk();
        $this->assertStringNotContainsString('1234567890', $daftar->getContent());
        $this->assertSame('****7890', $daftar->json('data.0.account_number_masked'));

        $this->assertSame(1, ActivityLog::where('action', 'store.bank_account_added')->where('user_id', $user->id)->count());
    }

    public function test_rekening_menolak_kembar_melebihi_batas_dan_data_tidak_valid(): void
    {
        [, $token, $toko] = $this->penjual();

        $this->tambahRekening($token)->assertStatus(201);

        // Nomor sama di bank sama (penulisan berbeda): ditolak.
        $this->tambahRekening($token, ['account_number' => '1234567890'])
            ->assertStatus(422)->assertJsonValidationErrors(['account_number']);

        // Nomor sama di bank lain: boleh.
        $this->tambahRekening($token, ['bank_code' => 'mandiri', 'bank_name' => 'Bank Mandiri'])->assertStatus(201);

        // Sampai batas 5, lalu ditolak.
        foreach (['1111111111', '2222222222', '3333333333'] as $nomor) {
            $this->tambahRekening($token, ['account_number' => $nomor])->assertStatus(201);
        }

        $this->tambahRekening($token, ['account_number' => '4444444444'])
            ->assertStatus(422)->assertJsonValidationErrors(['bank_account']);

        $this->assertSame(5, StoreBankAccount::where('store_id', $toko->id)->count());

        // Data tidak valid.
        [, $tokenBaru] = $this->penjual();

        $this->panggil('POST', '/api/v1/seller/store/bank-accounts', [], $tokenBaru)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bank_code', 'bank_name', 'account_name', 'account_number']);

        foreach ([['account_number' => '123'], ['account_number' => 'abc12345678'], ['bank_code' => 'bank bca'], ['account_name' => 'A']] as $salah) {
            $this->tambahRekening($tokenBaru, $salah)->assertStatus(422);
        }

        $this->assertSame(0, StoreBankAccount::count() - 5);
    }

    public function test_rekening_utama_berpindah_dan_menghapus_yang_utama_menaikkan_rekening_terbaru(): void
    {
        [, $token] = $this->penjual();

        $a = $this->tambahRekening($token, ['account_number' => '1111111111'])->json('data.id');
        $b = $this->tambahRekening($token, ['account_number' => '2222222222'])->json('data.id');
        $c = $this->tambahRekening($token, ['account_number' => '3333333333'])->json('data.id');

        $utama = fn() => StoreBankAccount::where('is_default', true)->pluck('uuid')->all();

        $this->assertSame([$a], $utama());

        $this->panggil('POST', "/api/v1/seller/store/bank-accounts/{$b}/default", [], $token)
            ->assertOk()->assertJson(['data' => ['id' => $b, 'is_default' => true]]);
        $this->assertSame([$b], $utama());

        // Hapus yang utama (B): rekening terbaru yang tersisa (C) naik.
        $this->panggil('DELETE', "/api/v1/seller/store/bank-accounts/{$b}", [], $token)
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame([$c], $utama());
        $this->assertSame(2, StoreBankAccount::count());

        // Rekening baru yang langsung dijadikan utama memindahkan status utama.
        $d = $this->tambahRekening($token, ['account_number' => '4444444444', 'is_default' => true])->json('data.id');
        $this->assertSame([$d], $utama());

        // Daftar: utama dulu.
        $daftar = $this->panggil('GET', '/api/v1/seller/store/bank-accounts', [], $token)->assertOk();
        $this->assertSame($d, $daftar->json('data.0.id'));

        // Hapus semua, lalu rekening berikutnya otomatis menjadi utama lagi.
        foreach ([$a, $c, $d] as $id) {
            $this->panggil('DELETE', "/api/v1/seller/store/bank-accounts/{$id}", [], $token)->assertOk();
        }
        $this->panggil('GET', '/api/v1/seller/store/bank-accounts', [], $token)->assertOk()->assertJson(['data' => []]);

        $this->tambahRekening($token, ['account_number' => '5555555555'])
            ->assertStatus(201)->assertJson(['data' => ['is_default' => true]]);
    }

    public function test_seller_tidak_bisa_menyentuh_rekening_toko_lain(): void
    {
        [, $tokenA] = $this->penjual();
        [, $tokenB] = $this->penjual();

        $id = $this->tambahRekening($tokenA)->json('data.id');

        $this->panggil('DELETE', "/api/v1/seller/store/bank-accounts/{$id}", [], $tokenB)->assertStatus(404)->assertJson(['success' => false]);
        $this->panggil('POST', "/api/v1/seller/store/bank-accounts/{$id}/default", [], $tokenB)->assertStatus(404);
        $this->panggil('GET', '/api/v1/seller/store/bank-accounts', [], $tokenB)->assertOk()->assertJson(['data' => []]);

        // Alamat yang bukan UUID atau tidak ada juga 404.
        $this->panggil('DELETE', '/api/v1/seller/store/bank-accounts/abc', [], $tokenA)->assertStatus(404);
        $this->panggil('DELETE', '/api/v1/seller/store/bank-accounts/00000000-0000-4000-8000-000000000000', [], $tokenA)->assertStatus(404);

        // Rekening A tetap utuh dan masih bisa diakses pemiliknya.
        $this->assertSame(1, StoreBankAccount::count());
        $this->panggil('GET', '/api/v1/seller/store/bank-accounts', [], $tokenA)->assertOk()->assertJsonCount(1, 'data');
    }
}
