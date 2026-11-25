<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class ShippingSettingSeeder extends Seeder
{
    /** firstOrCreate: perubahan dari admin tidak pernah ditimpa walau seeder dijalankan ulang. */
    public function run(): void
    {
        Setting::firstOrCreate(
            ['name' => 'shipping.platform_absorb_limit'],
            [
                'value'       => '5000',
                'type'        => 'int',
                'section'     => 'shipping',
                'is_public'   => false,
                'description' => 'Batas selisih ongkir yang ditanggung platform (rupiah)',
            ]
        );
    }
}
