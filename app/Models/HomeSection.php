<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HomeSection extends Model
{
    public const TYPES = [
        'banner_slider',
        'category_grid',
        'featured_products',
        'new_products',
        'best_sellers',
        'discounted_products',
    ];

    protected $fillable = ['code', 'title', 'type', 'config', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'config'     => 'array',
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    /** Bagian beranda yang aktif, berurutan. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
