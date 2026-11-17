<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Pengaturan bawaan. Nilai yang bertanda "sementara" menunggu keputusan bisnis.
     * firstOrCreate: pengaturan yang sudah ada TIDAK ditimpa, jadi perubahan
     * dari admin aman walau seeder dijalankan ulang.
     */
    private const DEFAULTS = [
        ['marketplace.name',             'Nexora',          'string', 'marketplace', true,  'Nama marketplace'],
        ['marketplace.maintenance_mode', '0',               'bool',   'marketplace', true,  'Mode pemeliharaan'],
        ['marketplace.commission_rate',  '5',               'float',  'marketplace', false, 'Komisi default marketplace (persen) - SEMENTARA'],
        ['marketplace.service_fee',      '0',               'int',    'marketplace', false, 'Biaya layanan per checkout (rupiah)'],
        ['payment.expiry_hours',         '24',              'int',    'payment',     false, 'Batas waktu pembayaran (jam) - SEMENTARA'],
        ['payment.allowed_types',        '["bank_transfer"]', 'json', 'payment',     true,  'Metode pembayaran yang aktif'],
        ['order.auto_complete_days',     '3',               'int',    'order',       false, 'Hari sampai pesanan terkirim selesai otomatis'],
        ['withdrawal.min_amount',        '50000',           'int',    'withdrawal',  false, 'Penarikan dana minimal (rupiah)'],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as [$name, $value, $type, $section, $isPublic, $description]) {
            Setting::firstOrCreate(
                ['name' => $name],
                [
                    'value'       => $value,
                    'type'        => $type,
                    'section'     => $section,
                    'is_public'   => $isPublic,
                    'description' => $description,
                ]
            );
        }
    }
}
