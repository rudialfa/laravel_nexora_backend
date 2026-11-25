<?php

namespace App\Services;

use App\Exceptions\BiteshipException;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pengiriman: permintaan kurir ke Biteship, pembatalan, pelacakan, dan pengolahan webhook.
 * Perpindahan status pesanan tetap lewat Order::transitionTo() dengan aktor sistem.
 */
class ShipmentService
{
    public const PESAN_SUDAH_DIBUAT  = 'Pengiriman untuk pesanan ini sudah dibuat.';
    public const PESAN_BUKAN_DIKEMAS = 'Pengiriman hanya dapat dibuat untuk pesanan yang sudah dikemas.';

    /** Penjemputan boleh diminta ulang jika pengiriman sebelumnya gagal atau dibatalkan. */
    private const BISA_ULANG = ['rejected', 'courier_not_found', 'cancelled'];

    /** Order Biteship masih boleh dibatalkan selama kurir belum mengambil paket. */
    private const BELUM_DIJEMPUT = ['pending', 'confirmed', 'allocated', 'picking_up'];

    private const EVENT = ['order.status', 'order.price', 'order.waybill_id'];

    private const RANTAI = [Order::STATUS_PACKED, Order::STATUS_SHIPPED, Order::STATUS_IN_TRANSIT, Order::STATUS_DELIVERED];

    public function __construct(private readonly BiteshipShipmentClient $client) {}

    // ---------------------------------------------------------------- SELLER

    /**
     * Seller meminta penjemputan. Pesanan tetap "dikemas" sampai kurir benar-benar menjemput.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws BiteshipException
     */
    public function ship(Store $store, string $uuid, User $actor, array $data): Order
    {
        return DB::transaction(function () use ($store, $uuid, $data) {
            $order = Order::query()
                ->where('store_id', $store->id)
                ->whereNotNull('paid_at')
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== Order::STATUS_PACKED) {
                $this->reject('order', self::PESAN_BUKAN_DIKEMAS);
            }

            $shipment = Shipment::query()->where('order_id', $order->id)->lockForUpdate()->first();

            if ($shipment !== null && ! in_array($shipment->status, self::BISA_ULANG, true)) {
                $this->reject('order', self::PESAN_SUDAH_DIBUAT);
            }

            // Kegagalan Biteship melempar pengecualian dan seluruh perubahan dibatalkan.
            $hasil = $this->client->createOrder($this->muatOrder($order, $store, $data, $shipment !== null));

            $isi = [
                'biteship_order_id' => $hasil['id'],
                'tracking_id'       => $hasil['tracking_id'],
                'waybill_id'        => $hasil['waybill_id'],
                'courier_company'   => $order->courier_company,
                'courier_type'      => $order->courier_type,
                'courier_price'     => $hasil['price'] > 0 ? $hasil['price'] : null,
                'driver'            => null,
                'raw_response'      => $hasil['raw'],
            ];

            if ($shipment === null) {
                $shipment = new Shipment(['order_id' => $order->id] + $isi);
                $shipment->save();
            } else {
                $shipment->forceFill($isi)->save();
            }

            $shipment->applyStatus($hasil['status'], ['source' => 'create']);

            return $order->fresh();
        });
    }

    /**
     * Membatalkan order di Biteship setelah pesanan dibatalkan, selama paket belum dijemput.
     * Kegagalan hanya dicatat: pembatalan pesanan tidak boleh ikut gagal.
     */
    public function cancelBiteship(Order $order, string $alasan): void
    {
        $shipment = Shipment::query()->where('order_id', $order->id)->first();

        if ($shipment === null || blank($shipment->biteship_order_id) || ! in_array($shipment->status, self::BELUM_DIJEMPUT, true)) {
            return;
        }

        try {
            $this->client->cancelOrder($shipment->biteship_order_id, 'Dibatalkan penjual: ' . $alasan);
            $shipment->applyStatus('cancelled', ['source' => 'seller_cancel']);
        } catch (BiteshipException $e) {
            Log::warning('Pembatalan order Biteship gagal', ['order' => $order->number, 'kode' => $e->kode]);
        }
    }

    // ------------------------------------------------------------- PELACAKAN

    /**
     * Resi dan riwayat pelacakan. Seller juga melihat telepon kurir dan selisih ongkir.
     *
     * @return array<string, mixed>
     */
    public function tracking(Order $order, string $peran): array
    {
        $hasil = ['order' => ['id' => $order->uuid, 'number' => $order->number, 'status' => $order->status], 'shipment' => null];

        $s = Shipment::query()->with('trackingHistories')->where('order_id', $order->id)->first();

        if ($s === null) {
            return $hasil;
        }

        $d      = (array) $s->driver;
        $driver = array_filter(
            ['name' => $d['name'] ?? null, 'plate_number' => $d['plate_number'] ?? null, 'link' => $d['link'] ?? null]
                + ($peran === 'seller' ? ['phone' => $d['phone'] ?? null] : []),
            fn($nilai) => $nilai !== null
        );

        $hasil['shipment'] = [
            'status'          => $s->status,
            'courier_company' => $s->courier_company,
            'courier_type'    => $s->courier_type,
            'waybill_id'      => $s->waybill_id,
            'driver'          => $driver === [] ? null : $driver,
            'picked_at'       => $this->iso($s->picked_at),
            'delivered_at'    => $this->iso($s->delivered_at),
            'history'         => $s->trackingHistories->map(fn($h) => [
                'status'     => $h->status,
                'note'       => $h->note,
                'created_at' => $this->iso($h->created_at),
            ])->values()->all(),
        ];

        if ($peran === 'seller') {
            $hasil['shipping_difference'] = $this->selisih($order, $s);
        }

        return $hasil;
    }

    // -------------------------------------------------------------- WEBHOOK

    /**
     * Mengolah webhook Biteship. Selalu mencatat ke log webhook.
     *
     * @param  array<string, mixed>  $payload
     * @return array{http: int, status: string, message: string}
     */
    public function handleNotification(array $payload, ?string $ip, bool $terotorisasi): array
    {
        $event   = is_string($payload['event'] ?? null) ? $payload['event'] : null;
        $orderId = is_string($payload['order_id'] ?? null) ? $payload['order_id'] : null;

        $log = new WebhookLog;
        $log->forceFill([
            'provider'          => WebhookLog::PROVIDER_BITESHIP,
            'event'             => $event === null ? null : Str::limit($event, 50, ''),
            'external_id'       => $orderId === null ? null : Str::limit($orderId, 100, ''),
            'payload'           => $payload,
            'ip_address'        => $ip,
            'processing_status' => WebhookLog::STATUS_RECEIVED,
        ])->save();

        $selesai = function (string $status, string $pesan, int $http, ?bool $valid = null) use ($log) {
            $log->forceFill([
                'processing_status' => $status,
                'error'             => $status === WebhookLog::STATUS_FAILED ? $pesan : null,
                'is_valid'          => $valid ?? $log->is_valid,
                'processed_at'      => now(),
            ])->save();

            return ['http' => $http, 'status' => $status, 'message' => $pesan];
        };

        if (! $terotorisasi) {
            return $selesai(WebhookLog::STATUS_FAILED, 'Autentikasi webhook tidak valid.', 401, false);
        }

        if ($event === null || $orderId === null || $orderId === '') {
            return $selesai(WebhookLog::STATUS_FAILED, 'Payload notifikasi tidak lengkap.', 400, true);
        }

        if (! in_array($event, self::EVENT, true)) {
            return $selesai(WebhookLog::STATUS_IGNORED, 'Event tidak dikenal.', 200, true);
        }

        if ($event === 'order.status' && (! is_string($payload['status'] ?? null) || $payload['status'] === '')) {
            return $selesai(WebhookLog::STATUS_FAILED, 'Status pengiriman tidak ada pada notifikasi.', 400, true);
        }

        $shipment = Shipment::query()->where('biteship_order_id', $orderId)->first();

        if ($shipment === null) {
            return $selesai(WebhookLog::STATUS_IGNORED, 'Pengiriman tidak dikenal.', 200, true);
        }

        $hasil = DB::transaction(fn() => $this->terapkan($shipment->id, $event, $payload));

        return $selesai($hasil[0], $hasil[1], 200, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string} status log dan pesan
     */
    private function terapkan(int $shipmentId, string $event, array $payload): array
    {
        $s = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);

        $ubah = $this->bidang($s, $payload);

        if ($ubah !== []) {
            $s->forceFill($ubah)->save();
        }

        if ($event !== 'order.status') {
            return [WebhookLog::STATUS_PROCESSED, 'Data pengiriman diperbarui.'];
        }

        // Status yang sama tidak dicatat dua kali: webhook yang dikirim ulang aman.
        if (! $s->applyStatus($payload['status'], $payload)) {
            return [WebhookLog::STATUS_IGNORED, 'Status pengiriman sudah tercatat.'];
        }

        return $this->majukanPesanan($s, Shipment::normalizeStatus($payload['status']));
    }

    /** @return array{0: string, 1: string} */
    private function majukanPesanan(Shipment $s, string $status): array
    {
        $target = match ($status) {
            'picked'                     => Order::STATUS_SHIPPED,
            'in_transit', 'dropping_off' => Order::STATUS_IN_TRANSIT,
            'delivered'                  => Order::STATUS_DELIVERED,
            default                      => null,
        };

        if ($target === null) {
            return [WebhookLog::STATUS_PROCESSED, 'Status pengiriman dicatat.'];
        }

        $order = Order::query()->lockForUpdate()->find($s->order_id);

        if ($order === null) {
            return [WebhookLog::STATUS_FAILED, 'Pesanan untuk pengiriman ini tidak ditemukan.'];
        }

        if (in_array($order->status, [Order::STATUS_COMPLETED, Order::STATUS_REFUNDED], true)) {
            return [WebhookLog::STATUS_PROCESSED, 'Pesanan sudah melewati tahap pengiriman.'];
        }

        if (! in_array($order->status, self::RANTAI, true)) {
            return [WebhookLog::STATUS_FAILED, "Status pengiriman {$status} diterima untuk pesanan berstatus {$order->status}. Perlu ditinjau manual."];
        }

        $langkah = match ($target) {
            Order::STATUS_SHIPPED    => [Order::STATUS_SHIPPED],
            Order::STATUS_IN_TRANSIT => [Order::STATUS_SHIPPED, Order::STATUS_IN_TRANSIT],
            default                  => [Order::STATUS_SHIPPED, Order::STATUS_DELIVERED],
        };

        foreach ($langkah as $ke) {
            // Langkah yang sudah terlewati (misalnya "dijemput") tidak diulang.
            if (array_search($order->status, self::RANTAI, true) >= array_search($ke, self::RANTAI, true)) {
                continue;
            }

            if (! $order->canTransitionTo($ke, Order::ACTOR_SYSTEM)) {
                break;
            }

            $order->transitionTo($ke, Order::ACTOR_SYSTEM, null, 'Pembaruan pengiriman: ' . $status);
        }

        return [WebhookLog::STATUS_PROCESSED, 'Status pengiriman dan pesanan diperbarui.'];
    }

    /**
     * Kolom pengiriman yang bisa diperbarui dari isi webhook.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function bidang(Shipment $s, array $payload): array
    {
        $ubah = [];

        if (is_string($payload['courier_waybill_id'] ?? null) && $payload['courier_waybill_id'] !== '') {
            $ubah['waybill_id'] = Str::limit($payload['courier_waybill_id'], 64, '');
        }

        if (is_string($payload['courier_tracking_id'] ?? null) && $payload['courier_tracking_id'] !== '') {
            $ubah['tracking_id'] = Str::limit($payload['courier_tracking_id'], 64, '');
        }

        $harga = $payload['order_price'] ?? $payload['price'] ?? null;

        if (is_numeric($harga) && (int) $harga > 0) {
            $ubah['courier_price'] = (int) $harga;
        }

        $driver = array_filter([
            'name'         => $payload['courier_driver_name'] ?? null,
            'phone'        => $payload['courier_driver_phone'] ?? null,
            'photo_url'    => $payload['courier_driver_photo_url'] ?? null,
            'plate_number' => $payload['courier_driver_plate_number'] ?? null,
            'link'         => $payload['courier_link'] ?? null,
        ], fn($nilai) => is_string($nilai) && $nilai !== '');

        if ($driver !== []) {
            $ubah['driver'] = array_merge((array) $s->driver, $driver);
        }

        return $ubah;
    }

    // ---------------------------------------------------------------- PRIVAT

    /**
     * Selisih harga aktual Biteship dengan ongkir yang dibayar pembeli.
     * Selisih sampai batas ditanggung platform; kelebihannya ditanggung seller (diterapkan di Langkah 32).
     *
     * @return array<string, int>|null
     */
    private function selisih(Order $order, Shipment $s): ?array
    {
        if ($s->courier_price === null) {
            return null;
        }

        $batas = max(0, (int) Setting::getValue('shipping.platform_absorb_limit', 5000));
        $beda  = (int) $s->courier_price - (int) $order->shipping_cost;

        return [
            'buyer_paid'       => (int) $order->shipping_cost,
            'carrier_price'    => (int) $s->courier_price,
            'difference'       => $beda,
            'platform_absorbs' => max(0, min($beda, $batas)),
            'seller_charge'    => max(0, $beda - $batas),
        ];
    }

    /**
     * Isi permintaan order Biteship dari pesanan dan data toko.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function muatOrder(Order $order, Store $store, array $data, bool $ulang): array
    {
        $alamat = (array) $order->shipping_address;
        $jenis  = $data['delivery_type'] ?? 'now';

        $item   = $order->items()->get();
        $varian = ProductVariant::query()->whereIn('id', $item->pluck('variant_id'))->get()->keyBy('id');

        $barang = $item->map(function ($i) use ($varian) {
            $v = $varian->get($i->variant_id);

            return array_filter([
                'name'     => Str::limit($i->product_name . ' - ' . $i->variant_name, 100, ''),
                'value'    => (int) $i->unit_price,
                'quantity' => (int) $i->quantity,
                'weight'   => max(1, (int) $i->weight_gram),
                'length'   => (int) $v?->length_cm,
                'width'    => (int) $v?->width_cm,
                'height'   => (int) $v?->height_cm,
            ], fn($nilai) => $nilai !== 0);
        })->values()->all();

        $payload = [
            'origin_contact_name'       => Str::limit($store->name, 50, ''),
            'origin_contact_phone'      => $store->phone,
            'origin_address'            => $this->gabung([$store->street, $store->district, $store->city, $store->province]),
            'origin_postal_code'        => (int) $store->postal_code,
            'destination_contact_name'  => $alamat['recipient_name'] ?? 'Penerima',
            'destination_contact_phone' => $alamat['phone'] ?? null,
            'destination_address'       => $this->gabung([$alamat['street'] ?? null, $alamat['district'] ?? null, $alamat['city'] ?? null, $alamat['province'] ?? null]),
            'destination_postal_code'   => (int) ($alamat['postal_code'] ?? 0),
            'courier_company'           => strtolower((string) $order->courier_company),
            'courier_type'              => strtolower((string) $order->courier_type),
            'delivery_type'             => $jenis,
            'reference_id'              => $ulang ? Str::limit($order->number . '-' . strtolower(Str::random(4)), 50, '') : $order->number,
            'order_note'                => $data['note'] ?? null,
            'metadata'                  => ['order_number' => $order->number, 'platform' => 'nexora'],
            'items'                     => $barang,
        ];

        if ($store->latitude !== null && $store->longitude !== null) {
            $payload['origin_coordinate'] = ['latitude' => (float) $store->latitude, 'longitude' => (float) $store->longitude];
        }

        if (($alamat['latitude'] ?? null) !== null && ($alamat['longitude'] ?? null) !== null) {
            $payload['destination_coordinate'] = ['latitude' => (float) $alamat['latitude'], 'longitude' => (float) $alamat['longitude']];
        }

        if ($jenis === 'scheduled') {
            $payload['delivery_date'] = $data['delivery_date'] ?? null;
            $payload['delivery_time'] = $data['delivery_time'] ?? null;
        }

        return array_filter($payload, fn($nilai) => $nilai !== null && $nilai !== '');
    }

    /** @param  array<int, mixed>  $bagian */
    private function gabung(array $bagian): string
    {
        return implode(', ', array_filter($bagian, fn($b) => is_string($b) && trim($b) !== ''));
    }

    private function iso(mixed $nilai): ?string
    {
        return $nilai === null ? null : Carbon::parse($nilai)->toIso8601String();
    }

    /** @throws ValidationException */
    private function reject(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
