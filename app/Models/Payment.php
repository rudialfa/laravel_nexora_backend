<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUuids;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DENIED    = 'denied';
    public const STATUS_FAILED    = 'failed';

    /**
     * Kolom di sini diisi oleh server kita sendiri (dari data checkout dan
     * respons charge Midtrans), bukan dari input pengguna.
     * "status", "paid_at", dan kolom transaction/fraud/status_code sengaja
     * TIDAK bisa diisi massal: hanya berubah lewat notifikasi Midtrans yang
     * signature-nya terverifikasi.
     */
    protected $fillable = [
        'checkout_group_id',
        'attempt',
        'midtrans_order_id',
        'midtrans_transaction_id',
        'payment_type',
        'bank',
        'va_number',
        'payment_details',
        'gross_amount',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt'          => 'integer',
            'payment_details'  => 'array',
            'gross_amount'     => 'integer',
            'expires_at'       => 'datetime',
            'paid_at'          => 'datetime',
            'last_notified_at' => 'datetime',
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

    public function checkoutGroup(): BelongsTo
    {
        return $this->belongsTo(CheckoutGroup::class);
    }

    /**
     * Mengubah status transaksi Midtrans menjadi status pembayaran kita.
     * Mengembalikan null untuk status yang belum kita tangani (misalnya refund);
     * pemanggil harus mengabaikannya dan hanya mencatat log.
     */
    public static function mapMidtransStatus(string $transactionStatus, ?string $fraudStatus = null): ?string
    {
        $fraudStatus = $fraudStatus ?: null;

        return match ($transactionStatus) {
            'capture' => match ($fraudStatus) {
                null, 'accept' => self::STATUS_PAID,
                'challenge'    => self::STATUS_PENDING,
                default        => self::STATUS_DENIED,
            },
            'settlement' => self::STATUS_PAID,
            'pending'    => self::STATUS_PENDING,
            'expire'     => self::STATUS_EXPIRED,
            'cancel'     => self::STATUS_CANCELLED,
            'deny'       => self::STATUS_DENIED,
            'failure'    => self::STATUS_FAILED,
            default      => null,
        };
    }

    /**
     * Memeriksa signature_key notifikasi Midtrans:
     * SHA-512 dari order_id + status_code + gross_amount + ServerKey,
     * seluruhnya diambil dari isi notifikasi itu sendiri.
     */
    public static function isValidSignature(array $payload, string $serverKey): bool
    {
        if ($serverKey === '') {
            return false;
        }

        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $kolom) {
            if (! isset($payload[$kolom]) || ! is_scalar($payload[$kolom])) {
                return false;
            }
        }

        $expected = hash(
            'sha512',
            $payload['order_id'] . $payload['status_code'] . $payload['gross_amount'] . $serverKey
        );

        return hash_equals($expected, (string) $payload['signature_key']);
    }

    /**
     * Apakah nominal di notifikasi sama dengan nominal yang kita kirim ke Midtrans?
     * Midtrans mengirim nominal bertipe teks, misalnya "105000.00".
     */
    public function grossAmountMatches(array $payload): bool
    {
        if (! isset($payload['gross_amount']) || ! is_numeric($payload['gross_amount'])) {
            return false;
        }

        return (int) round((float) $payload['gross_amount']) === $this->gross_amount;
    }
}
