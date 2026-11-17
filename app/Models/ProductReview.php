<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductReview extends Model
{
    use HasUuids;

    public const MIN_RATING = 1;
    public const MAX_RATING = 5;

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_HIDDEN    = 'hidden';

    /**
     * Hanya isi ulasan yang boleh datang dari pengguna. order_item_id, user_id,
     * product_id, status, dan balasan seller ditentukan server, sehingga pengguna
     * tidak bisa mengulas produk yang tidak pernah dibelinya.
     */
    protected $fillable = ['rating', 'comment', 'is_anonymous'];

    protected function casts(): array
    {
        return [
            'rating'            => 'integer',
            'is_anonymous'      => 'boolean',
            'seller_replied_at' => 'datetime',
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

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReviewImage::class, 'review_id')->orderBy('sort_order');
    }
}
