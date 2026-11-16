<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menguji tabel dan relasi Batch 1.
 * RefreshDatabase memakai database uji sementara (SQLite di memori),
 * jadi database MySQL "nexora" Anda tidak tersentuh.
 */
class Batch1DatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(array $override = []): User
    {
        return User::create(array_merge([
            'name'     => 'Budi',
            'email'    => 'budi@nexora.test',
            'password' => 'rahasia123',
        ], $override));
    }

    public function test_user_otomatis_punya_uuid_dan_status_active(): void
    {
        $user = $this->buatUser()->fresh();

        $this->assertNotNull($user->uuid);
        $this->assertSame(36, strlen($user->uuid));
        $this->assertSame('active', $user->status);
    }

    public function test_status_tidak_bisa_diubah_lewat_mass_assignment(): void
    {
        $user = $this->buatUser(['status' => 'banned'])->fresh();

        $this->assertSame('active', $user->status);
    }

    public function test_email_dan_telepon_harus_unik(): void
    {
        $this->buatUser(['phone' => '628123456789']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->buatUser(['email' => 'lain@nexora.test', 'phone' => '628123456789']);
    }

    public function test_alamat_milik_user_dan_punya_uuid(): void
    {
        $user = $this->buatUser();

        $alamat = $user->addresses()->create([
            'label'          => 'Rumah',
            'recipient_name' => 'Budi',
            'phone'          => '628123456789',
            'province'       => 'DI Yogyakarta',
            'city'           => 'Sleman',
            'district'       => 'Depok',
            'postal_code'    => '55281',
            'street'         => 'Jl. Contoh No. 1',
            'is_default'     => true,
        ])->fresh();

        $this->assertSame($user->id, $alamat->user->id);
        $this->assertSame(36, strlen($alamat->uuid));
        $this->assertTrue($alamat->is_default);
    }

    public function test_alamat_soft_delete_tidak_hilang_dari_database(): void
    {
        $user = $this->buatUser();
        $alamat = $user->addresses()->create([
            'label' => 'Kantor',
            'recipient_name' => 'Budi',
            'phone' => '628123456789',
            'province' => 'DI Yogyakarta',
            'city' => 'Sleman',
            'district' => 'Depok',
            'postal_code' => '55281',
            'street' => 'Jl. Contoh No. 2',
        ]);

        $alamat->delete();

        $this->assertSoftDeleted('addresses', ['id' => $alamat->id]);
        $this->assertSame(0, $user->addresses()->count());
    }

    public function test_device_token_harus_unik(): void
    {
        $user = $this->buatUser();
        $user->deviceTokens()->create(['token' => 'token-abc', 'platform' => 'android']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $user->deviceTokens()->create(['token' => 'token-abc', 'platform' => 'ios']);
    }

    public function test_activity_log_tetap_ada_saat_user_dihapus_permanen(): void
    {
        $user = $this->buatUser();

        $log = ActivityLog::create([
            'user_id'    => $user->id,
            'action'     => 'auth.login',
            'properties' => ['via' => 'email'],
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertSame(['via' => 'email'], $log->fresh()->properties);
        $this->assertNotNull($log->fresh()->created_at);

        $user->forceDelete();

        $this->assertDatabaseHas('activity_logs', ['id' => $log->id, 'user_id' => null]);
    }
}
