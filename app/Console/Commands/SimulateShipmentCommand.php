<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Services\ShipmentService;
use Illuminate\Console\Command;

class SimulateShipmentCommand extends Command
{
    protected $signature = 'nexora:simulate-shipment {biteship_order_id : ID order Biteship (kolom biteship_order_id)} {status=picked : picked, in_transit, dropping_off, delivered, on_hold, rejected, dan lainnya}';

    protected $description = 'Mensimulasikan webhook Biteship untuk uji manual. Hanya untuk lingkungan lokal.';

    private const DIKENAL = [
        'confirmed',
        'allocated',
        'picking_up',
        'picked',
        'in_transit',
        'dropping_off',
        'return_in_transit',
        'on_hold',
        'delivered',
        'rejected',
        'courier_not_found',
        'returned',
        'cancelled',
        'disposed',
    ];

    public function handle(ShipmentService $shipments): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Perintah ini hanya untuk lingkungan pengembangan dan menolak berjalan di production.');

            return self::FAILURE;
        }

        $status = Shipment::normalizeStatus((string) $this->argument('status'));

        if (! in_array($status, self::DIKENAL, true)) {
            $this->error('Status tidak dikenal. Pilihan: ' . implode(', ', self::DIKENAL) . '.');

            return self::FAILURE;
        }

        $s = Shipment::query()->where('biteship_order_id', (string) $this->argument('biteship_order_id'))->first();

        if ($s === null) {
            $this->error('Pengiriman dengan order ID Biteship itu tidak ditemukan.');

            return self::FAILURE;
        }

        $hasil = $shipments->handleNotification(array_filter([
            'event'                       => 'order.status',
            'courier_tracking_id'         => $s->tracking_id,
            'courier_waybill_id'          => $s->waybill_id ?: 'SIM-' . strtoupper(substr(md5((string) $s->biteship_order_id), 0, 8)),
            'courier_company'             => strtoupper((string) $s->courier_company),
            'courier_type'                => strtoupper((string) $s->courier_type),
            'courier_driver_name'         => 'Kurir Simulasi',
            'courier_driver_plate_number' => 'B 0000 SIM',
            'order_id'                    => $s->biteship_order_id,
            'order_price'                 => $s->courier_price,
            'status'                      => $status,
        ], fn($nilai) => $nilai !== null), '127.0.0.1', true);

        $this->info("Hasil: {$hasil['status']} (HTTP {$hasil['http']}) - {$hasil['message']}");

        return $hasil['http'] < 400 ? self::SUCCESS : self::FAILURE;
    }
}
