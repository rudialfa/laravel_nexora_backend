<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Shipment extends Model
{
    use HasUuids;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_PICKED    = 'picked';
    public const STATUS_DELIVERED = 'delivered';

    /**
     * "status" dan kolom waktu sengaja TIDAK bisa diisi massal:
     * hanya berubah lewat applyStatus().
     */
    protected $fillable = [
        'order_id',
        'biteship_order_id',
        'tracking_id',
        'waybill_id',
        'courier_company',
        'courier_type',
        'courier_price',
        'driver',
        'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'courier_price' => 'integer',
            'driver'        => 'array',
            'raw_response'  => 'array',
            'picked_at'     => 'datetime',
            'delivered_at'  => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function trackingHistories(): HasMany
    {
        return $this->hasMany(ShipmentTrackingHistory::class)->orderBy('id');
    }

    /**
     * Dokumentasi Biteship menulis status dalam dua gaya (pickingUp dan picking_up).
     * Semuanya kita ubah ke snake_case huruf kecil: picking_up, in_transit, dll.
     */
    public static function normalizeStatus(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === strtoupper($raw)) {
            $raw = strtolower($raw);
        }

        return Str::snake($raw);
    }

    /**
     * Mencatat status pengiriman baru.
     *
     * Mengembalikan false (dan tidak mencatat apa pun) jika status yang sama
     * sudah tercatat, sehingga webhook yang dikirim ulang tidak menggandakan
     * riwayat. Baris dikunci agar dua webhook bersamaan tidak sama-sama lolos.
     */
    public function applyStatus(string $rawStatus, ?array $payload = null): bool
    {
        $status = self::normalizeStatus($rawStatus);

        return DB::transaction(function () use ($status, $payload) {
            $shipment = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($shipment->status === $status) {
                return false;
            }

            $shipment->status = $status;

            if ($status === self::STATUS_PICKED && $shipment->picked_at === null) {
                $shipment->picked_at = now();
            }

            if ($status === self::STATUS_DELIVERED) {
                $shipment->delivered_at = now();
            }

            $shipment->save();

            $shipment->trackingHistories()->create([
                'status'  => $status,
                'payload' => $payload,
            ]);

            $this->refresh();

            return true;
        });
    }
}
