<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class VoucherUsage extends Model
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_USED     = 'used';
    public const STATUS_RELEASED = 'released';

    /** "status" hanya berubah lewat markUsed() dan release(). */
    protected $fillable = [
        'voucher_id',
        'user_id',
        'checkout_group_id',
        'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'integer',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkoutGroup(): BelongsTo
    {
        return $this->belongsTo(CheckoutGroup::class);
    }

    /** Dipanggil saat pembayaran terverifikasi. Mengembalikan false jika bukan "reserved". */
    public function markUsed(): bool
    {
        return DB::transaction(function () {
            $pemakaian = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($pemakaian->status !== self::STATUS_RESERVED) {
                return false;
            }

            $pemakaian->status = self::STATUS_USED;
            $pemakaian->save();
            $this->refresh();

            return true;
        });
    }

    /**
     * Dipanggil saat checkout batal atau kedaluwarsa: kuota dikembalikan.
     * Aman dipanggil berulang; hanya yang berstatus "reserved" yang diproses.
     */
    public function release(): bool
    {
        return DB::transaction(function () {
            $pemakaian = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($pemakaian->status !== self::STATUS_RESERVED) {
                return false;
            }

            $pemakaian->status = self::STATUS_RELEASED;
            $pemakaian->save();

            Voucher::withTrashed()
                ->whereKey($pemakaian->voucher_id)
                ->where('used_count', '>', 0)
                ->decrement('used_count');

            $this->refresh();

            return true;
        });
    }
}
