<?php

namespace Database\Seeders;

use App\Models\EcosystemApp;
use Illuminate\Database\Seeder;

class EcosystemAppSeeder extends Seeder
{
    /**
     * Hanya nama dan slug. Deskripsi dan link Play Store diisi nanti dari data
     * yang Anda berikan. firstOrCreate: data yang sudah diubah tidak ditimpa.
     */
    private const APPS = [
        ['Absen Pintar', 'absen-pintar'],
        ['OSPay PPOB',   'ospay-ppob'],
        ['Gudang Pro',   'gudang-pro'],
        ['Cipta Usaha',  'cipta-usaha'],
    ];

    public function run(): void
    {
        foreach (self::APPS as $urutan => [$nama, $slug]) {
            EcosystemApp::firstOrCreate(
                ['slug' => $slug],
                ['name' => $nama, 'sort_order' => $urutan + 1]
            );
        }
    }
}
