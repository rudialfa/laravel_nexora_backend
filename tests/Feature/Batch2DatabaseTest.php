<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreApplication;
use App\Models\StoreCourierSetting;
use App\Models\StoreFollower;
use App\Models\StoreOperatingHour;
use App\Models\StoreStaff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menguji tabel dan relasi Batch 2 (toko dan seller).
 * Berjalan di SQLite memori, tidak menyentuh MySQL.
 */
class Batch2DatabaseTest extends TestCase
{
    use RefreshDatabase;

    private int $urut = 0;

    private function buatUser(): User
    {
        $this->urut++;

        return User::create([
            'name'     => "User {$this->urut}",
            'email'    => "user{$this->urut}@nexora.test",
            'password' => 'rahasia123',
        ]);
    }

    private function buatToko(User $pemilik, string $slug = 'toko-budi'): Store
    {
        $toko = new Store([
            'name'        => 'Toko Budi',
            'slug'        => $slug,
            'phone'       => '628123456789',
            'province'    => 'DI Yogyakarta',
            'city'        => 'Sleman',
            'district'    => 'Depok',
            'postal_code' => '55281',
            'street'      => 'Jl. Contoh No. 1',
        ]);
        $toko->owner_id = $pemilik->id;
        $toko->save();

        return $toko->fresh();
    }

    public function test_toko_punya_uuid_status_active_dan_nilai_awal(): void
    {
        $toko = $this->buatToko($this->buatUser());

        $this->assertSame(36, strlen($toko->uuid));
        $this->assertSame('active', $toko->status);
        $this->assertTrue($toko->is_open);
        $this->assertSame('Asia/Jakarta', $toko->timezone);
        $this->assertSame(0, $toko->followers_count);
    }

    public function test_satu_pengguna_hanya_boleh_punya_satu_toko(): void
    {
        $pemilik = $this->buatUser();
        $this->buatToko($pemilik, 'toko-satu');

        $this->expectException(QueryException::class);
        $this->buatToko($pemilik, 'toko-dua');
    }

    public function test_slug_toko_harus_unik(): void
    {
        $this->buatToko($this->buatUser(), 'toko-sama');

        $this->expectException(QueryException::class);
        $this->buatToko($this->buatUser(), 'toko-sama');
    }

    public function test_kolom_sensitif_toko_tidak_bisa_diisi_massal(): void
    {
        $pemilik = $this->buatUser();

        $toko = new Store([
            'name' => 'Toko Nakal',
            'slug' => 'toko-nakal',
            'phone' => '628111',
            'province' => 'A',
            'city' => 'B',
            'district' => 'C',
            'postal_code' => '11111',
            'street' => 'Jl. X',
            // Semua di bawah ini harus diabaikan oleh mass assignment:
            'status'          => 'suspended',
            'commission_rate' => 0,
            'rating_avg'      => 5,
            'followers_count' => 9999,
            'owner_id'        => 999,
        ]);

        $this->assertNull($toko->owner_id);

        $toko->owner_id = $pemilik->id;
        $toko->save();
        $toko = $toko->fresh();

        $this->assertSame('active', $toko->status);
        $this->assertNull($toko->commission_rate);
        $this->assertSame('0.00', $toko->rating_avg);
        $this->assertSame(0, $toko->followers_count);
        $this->assertSame($pemilik->id, $toko->owner_id);
    }

    public function test_relasi_pemilik_dan_toko(): void
    {
        $pemilik = $this->buatUser();
        $toko    = $this->buatToko($pemilik);

        $this->assertSame($pemilik->id, $toko->owner->id);
        $this->assertSame($toko->id, $pemilik->fresh()->store->id);
    }

    public function test_pengajuan_toko_menyimpan_nik_terenkripsi_dan_hash(): void
    {
        $user = $this->buatUser();

        $pengajuan = new StoreApplication([
            'store_name'     => 'Toko Budi',
            'phone'          => '628123456789',
            'province'       => 'DI Yogyakarta',
            'city'           => 'Sleman',
            'district'       => 'Depok',
            'postal_code'    => '55281',
            'street'         => 'Jl. Contoh No. 1',
            'id_holder_name' => 'Budi Santoso',
            'id_photo_path'  => 'private/ktp/budi.jpg',
        ]);
        $pengajuan->user_id = $user->id;
        $pengajuan->setIdNumber('3404010101010001');
        $pengajuan->save();

        // Di database, NIK tidak tersimpan sebagai teks biasa.
        $mentah = \DB::table('store_applications')->where('id', $pengajuan->id)->first();
        $this->assertStringNotContainsString('3404010101010001', $mentah->id_number_encrypted);
        $this->assertSame(64, strlen($mentah->id_number_hash));

        // Lewat model, NIK terbaca kembali dengan benar.
        $this->assertSame('3404010101010001', $pengajuan->fresh()->id_number_encrypted);

        // NIK dan foto identitas tidak ikut keluar saat dijadikan JSON.
        $json = $pengajuan->fresh()->toArray();
        $this->assertArrayNotHasKey('id_number_encrypted', $json);
        $this->assertArrayNotHasKey('id_photo_path', $json);

        $this->assertSame('pending', $pengajuan->fresh()->status);
    }

    public function test_nik_yang_sama_tidak_bisa_dipakai_dua_kali(): void
    {
        $data = [
            'store_name' => 'Toko',
            'phone' => '628111',
            'province' => 'A',
            'city' => 'B',
            'district' => 'C',
            'postal_code' => '11111',
            'street' => 'Jl. X',
            'id_holder_name' => 'Budi',
            'id_photo_path' => 'private/ktp/x.jpg',
        ];

        foreach ([$this->buatUser(), $this->buatUser()] as $i => $user) {
            $pengajuan = new StoreApplication($data);
            $pengajuan->user_id = $user->id;
            $pengajuan->setIdNumber('3404010101010001');

            if ($i === 1) {
                $this->expectException(QueryException::class);
            }
            $pengajuan->save();
        }
    }

    public function test_jam_operasional_unik_per_hari_per_toko(): void
    {
        $toko = $this->buatToko($this->buatUser());
        $toko->operatingHours()->create([
            'day_of_week' => 1,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $this->expectException(QueryException::class);
        $toko->operatingHours()->create([
            'day_of_week' => 1,
            'open_time' => '09:00',
            'close_time' => '18:00',
        ]);
    }

    public function test_kurir_toko_unik_per_kode(): void
    {
        $toko = $this->buatToko($this->buatUser());
        $toko->courierSettings()->create(['courier_code' => 'jne']);

        $this->expectException(QueryException::class);
        $toko->courierSettings()->create(['courier_code' => 'jne']);
    }

    public function test_staff_menyimpan_izin_sebagai_array_dan_unik_per_toko(): void
    {
        $toko  = $this->buatToko($this->buatUser());
        $staff = $this->buatUser();

        $baris = $toko->staff()->create([
            'user_id'     => $staff->id,
            'permissions' => ['product.manage', 'order.process'],
        ])->fresh();

        $this->assertSame(['product.manage', 'order.process'], $baris->permissions);
        $this->assertSame('active', $baris->status);

        $this->expectException(QueryException::class);
        $toko->staff()->create(['user_id' => $staff->id, 'permissions' => []]);
    }

    public function test_pengikut_toko_unik_dan_terbaca_dari_dua_arah(): void
    {
        $toko     = $this->buatToko($this->buatUser());
        $pengikut = $this->buatUser();

        StoreFollower::create(['store_id' => $toko->id, 'user_id' => $pengikut->id]);

        $this->assertSame(1, $toko->followers()->count());
        $this->assertSame(1, $pengikut->followedStores()->count());

        $this->expectException(QueryException::class);
        StoreFollower::create(['store_id' => $toko->id, 'user_id' => $pengikut->id]);
    }

    public function test_hapus_permanen_toko_ikut_menghapus_data_turunannya(): void
    {
        $toko     = $this->buatToko($this->buatUser());
        $pengikut = $this->buatUser();

        $toko->operatingHours()->create(['day_of_week' => 2, 'open_time' => '08:00', 'close_time' => '17:00']);
        $toko->courierSettings()->create(['courier_code' => 'sicepat']);
        StoreFollower::create(['store_id' => $toko->id, 'user_id' => $pengikut->id]);

        $toko->forceDelete();

        $this->assertSame(0, StoreOperatingHour::count());
        $this->assertSame(0, StoreCourierSetting::count());
        $this->assertSame(0, StoreFollower::count());
        $this->assertSame(0, StoreStaff::count());
    }

    public function test_soft_delete_toko_tidak_menghapus_baris_database(): void
    {
        $toko = $this->buatToko($this->buatUser());

        $toko->delete();

        $this->assertSoftDeleted('stores', ['id' => $toko->id]);
    }
}
