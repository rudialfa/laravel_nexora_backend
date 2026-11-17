<?php

namespace App\Models;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Voucher extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED      = 'fixed';

    /**
     * "store_id", "used_count", dan "created_by" sengaja TIDAK bisa diisi massal:
     * pemilik voucher ditentukan server (admin atau toko yang sedang login),
     * dan used_count hanya berubah lewat redeem()/release().
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'max_discount',
        'min_purchase',
        'quota',
        'per_user_limit',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'max_discount'   => 'integer',
            'min_purchase'   => 'integer',
            'quota'          => 'integer',
            'used_count'     => 'integer',
            'per_user_limit' => 'integer',
            'starts_at'      => 'datetime',
            'ends_at'        => 'datetime',
            'is_active'      => 'boolean',
        ];
    }

    /** Kode selalu disimpan huruf besar tanpa spasi di pinggir. */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn(string $value) => strtoupper(trim($value)),
        );
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    /** Apakah voucher ini bisa dipakai pada waktu tersebut (aktif, dalam masa berlaku, kuota tersisa)? */
    public function isUsableAt(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->lte($at))
            && ($this->ends_at === null || $this->ends_at->gte($at))
            && ($this->quota === null || (int) $this->used_count < $this->quota);
    }

    /**
     * Besar potongan untuk subtotal tertentu (rupiah utuh).
     * 0 jika subtotal belum mencapai minimum belanja.
     */
    public function calculateDiscount(int $subtotal): int
    {
        if ($subtotal <= 0 || $subtotal < (int) $this->min_purchase) {
            return 0;
        }

        $diskon = $this->discount_type === self::TYPE_PERCENTAGE
            ? intdiv($subtotal * min((int) $this->discount_value, 100), 100)
            : (int) $this->discount_value;

        if ($this->max_discount !== null) {
            $diskon = min($diskon, $this->max_discount);
        }

        // Potongan tidak pernah melebihi subtotal.
        return min($diskon, $subtotal);
    }

    /**
     * Memakai voucher (status "reserved") untuk satu checkout.
     *
     * Seluruh pemeriksaan dilakukan setelah baris voucher dikunci, sehingga dua
     * pembeli yang bersamaan tidak bisa sama-sama lolos dari kuota yang sama.
     *
     * @throws DomainException
     */
    public function redeem(User $user, CheckoutGroup $group, int $subtotal): VoucherUsage
    {
        return DB::transaction(function () use ($user, $group, $subtotal) {
            $voucher = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $voucher->isUsableAt()) {
                throw new DomainException('Voucher tidak berlaku atau kuotanya sudah habis.');
            }

            $diskon = $voucher->calculateDiscount($subtotal);

            if ($diskon <= 0) {
                throw new DomainException('Pembelian belum memenuhi syarat voucher.');
            }

            $sudahDipakai = VoucherUsage::query()
                ->where('voucher_id', $voucher->id)
                ->where('user_id', $user->id)
                ->whereIn('status', [VoucherUsage::STATUS_RESERVED, VoucherUsage::STATUS_USED])
                ->count();

            if ($sudahDipakai >= $voucher->per_user_limit) {
                throw new DomainException('Batas penggunaan voucher untuk akun ini sudah tercapai.');
            }

            $pemakaian = VoucherUsage::create([
                'voucher_id'        => $voucher->id,
                'user_id'           => $user->id,
                'checkout_group_id' => $group->id,
                'discount_amount'   => $diskon,
            ]);

            $voucher->increment('used_count');

            return $pemakaian;
        });
    }
}
