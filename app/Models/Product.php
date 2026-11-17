<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_INACTIVE  = 'inactive';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Kolom sensitif sengaja TIDAK boleh diisi massal:
     * store_id, status, is_featured, price_min/max, dan semua statistik.
     * Semuanya hanya diubah oleh kode yang kita tulis sendiri.
     */
    protected $fillable = [
        'category_id',
        'brand_id',
        'name',
        'slug',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_featured'  => 'boolean',
            'price_min'    => 'integer',
            'price_max'    => 'integer',
            'rating_avg'   => 'decimal:2',
            'published_at' => 'datetime',
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

    /** Hanya produk yang tayang di marketplace. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wishlists')
            ->withPivot('created_at');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ProductQuestion::class);
    }

    /** Item pesanan yang berisi produk ini. */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Menghitung ulang rentang harga (price_min dan price_max)
     * dari varian aktif, memakai harga efektif (setelah diskon).
     */
    public function refreshPriceRange(): void
    {
        $prices = $this->variants()
            ->where('is_active', true)
            ->get()
            ->map(fn(ProductVariant $variant) => $variant->effectivePrice());

        $this->forceFill([
            'price_min' => $prices->min() ?? 0,
            'price_max' => $prices->max() ?? 0,
        ])->save();
    }
}
