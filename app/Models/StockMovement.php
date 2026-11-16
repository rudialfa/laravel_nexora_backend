<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    protected $table = 'product_stock_movements';

    /** Riwayat tidak pernah diubah, jadi tidak ada updated_at. */
    const UPDATED_AT = null;

    protected $fillable = [
        'variant_id',
        'user_id',
        'quantity_change',
        'stock_after',
        'reason',
        'reference_type',
        'reference_id',
        'note',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
