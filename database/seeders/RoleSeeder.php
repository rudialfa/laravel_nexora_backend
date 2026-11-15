<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Daftar role Nexora.
     * Memakai guard "web" (bawaan). Guard ini tetap berfungsi
     * untuk token Sanctum, jadi tidak perlu guard khusus.
     */
    public const ROLES = [
        'super_admin',
        'admin',
        'seller',
        'seller_staff',
        'customer',
    ];

    public function run(): void
    {
        // Kosongkan cache permission agar data baru terbaca.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $roleName) {
            // firstOrCreate: aman dijalankan berkali-kali, tidak membuat duplikat.
            Role::firstOrCreate([
                'name'       => $roleName,
                'guard_name' => 'web',
            ]);
        }
    }
}
