<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'product_name',
        'variant_name',
        'sku',
        'image_path',
        'options',
        'price',
        'unit_price',
        'quantity',
        'subtotal',
        'weight_gram',
    ];

    protected function casts(): array
    {
        return [
            'options'     => 'array',
            'price'       => 'integer',
            'unit_price'  => 'integer',
            'quantity'    => 'integer',
            'subtotal'    => 'integer',
            'weight_gram' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
