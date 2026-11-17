<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Jalankan seluruh seeder aplikasi. Semuanya aman dijalankan berulang kali.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SettingSeeder::class,
            EcosystemAppSeeder::class,
        ]);
    }
}
