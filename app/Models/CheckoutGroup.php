<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CheckoutGroup extends Model
{
    use HasUuids;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID            = 'paid';
    public const STATUS_EXPIRED         = 'expired';
    public const STATUS_CANCELLED       = 'cancelled';

    /**
     * Jumlah uang dihitung oleh server (service checkout), tidak pernah
     * dari input pengguna. "status" dan "paid_at" sengaja TIDAK bisa diisi
     * massal; keduanya hanya diubah oleh kode pembayaran yang terverifikasi.
     */
    protected $fillable = [
        'user_id',
        'number',
        'subtotal',
        'shipping_total',
        'discount_total',
        'service_fee',
        'grand_total',
        'payment_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'           => 'integer',
            'shipping_total'     => 'integer',
            'discount_total'     => 'integer',
            'service_fee'        => 'integer',
            'grand_total'        => 'integer',
            'payment_expires_at' => 'datetime',
            'paid_at'            => 'datetime',
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

    /** Contoh hasil: NXR-261116093045-K7QZ2 (maksimal 40 karakter untuk Midtrans). */
    public static function generateNumber(): string
    {
        return 'NXR-' . now()->format('ymdHis') . '-' . strtoupper(Str::random(5));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
