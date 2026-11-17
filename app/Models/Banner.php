<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    public const LINK_NONE     = 'none';
    public const LINK_PRODUCT  = 'product';
    public const LINK_STORE    = 'store';
    public const LINK_CATEGORY = 'category';
    public const LINK_URL      = 'url';

    protected $fillable = [
        'title',
        'image_path',
        'link_type',
        'link_value',
        'position',
        'sort_order',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
            'starts_at'  => 'datetime',
            'ends_at'    => 'datetime',
        ];
    }

    /** Banner yang sedang tayang (aktif dan dalam masa tayang), berurutan. */
    public function scopeLive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('is_active', true)
            ->where(fn(Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn(Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
