<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ProductVariant extends Model
{
    use HasUuids;

    /**
     * "stock" sengaja TIDAK ada di sini. Stok hanya boleh berubah
     * lewat adjustStock() agar selalu tercatat dan aman dari overselling.
     */
    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'options',
        'price',
        'discount_price',
        'discount_starts_at',
        'discount_ends_at',
        'weight_gram',
        'length_cm',
        'width_cm',
        'height_cm',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options'            => 'array',
            'price'              => 'integer',
            'discount_price'     => 'integer',
            'discount_starts_at' => 'datetime',
            'discount_ends_at'   => 'datetime',
            'stock'              => 'integer',
            'is_active'          => 'boolean',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    /**
     * Harga yang berlaku saat ini. Diskon dipakai hanya jika:
     * - discount_price terisi dan lebih murah dari harga normal,
     * - waktu sekarang berada di dalam masa diskon (jika masa diskon diatur).
     */
    public function effectivePrice(?CarbonInterface $at = null): int
    {
        $at ??= now();

        $diskonAktif = $this->discount_price !== null
            && $this->discount_price < $this->price
            && ($this->discount_starts_at === null || $this->discount_starts_at->lte($at))
            && ($this->discount_ends_at === null || $this->discount_ends_at->gte($at));

        return $diskonAktif ? $this->discount_price : $this->price;
    }

    /**
     * Mengubah stok dengan aman.
     *
     * - $delta negatif = stok keluar. Gagal (false) jika stok tidak cukup,
     *   sehingga stok tidak pernah menjadi minus walau ada banyak pembeli
     *   bersamaan (pengecekan dilakukan di dalam satu perintah UPDATE).
     * - $delta positif = stok masuk.
     * - Setiap perubahan tercatat di product_stock_movements.
     */
    public function adjustStock(
        int $delta,
        string $reason,
        ?Model $reference = null,
        ?int $userId = null,
        ?string $note = null
    ): bool {
        if ($delta === 0) {
            return true;
        }

        return DB::transaction(function () use ($delta, $reason, $reference, $userId, $note) {
            $query = static::query()->whereKey($this->getKey());

            if ($delta < 0) {
                $affected = $query->where('stock', '>=', -$delta)->decrement('stock', -$delta);
            } else {
                $affected = $query->increment('stock', $delta);
            }

            if ($affected === 0) {
                return false;
            }

            $this->refresh();

            StockMovement::create([
                'variant_id'      => $this->id,
                'user_id'         => $userId,
                'quantity_change' => $delta,
                'stock_after'     => $this->stock,
                'reason'          => $reason,
                'reference_type'  => $reference?->getMorphClass(),
                'reference_id'    => $reference?->getKey(),
                'note'            => $note,
            ]);

            return true;
        });
    }
}
