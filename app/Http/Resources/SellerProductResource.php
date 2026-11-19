<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk produk yang dilihat SELLER (pemilik produk).
 * Sengaja TIDAK memuat: id numerik, store_id, category_id, brand_id, deleted_at.
 *
 * @mixin \App\Models\Product
 */
class SellerProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $disk = Storage::disk('public');

        return [
            'id'           => $this->uuid,
            'name'         => $this->name,
            'slug'         => $this->slug,
            'description'  => $this->description,
            'status'       => $this->status,
            'is_featured'  => (bool) $this->is_featured,
            'category'     => $this->whenLoaded('category', fn() => [
                'id'   => $this->category->uuid,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'brand'        => $this->whenLoaded('brand', fn() => [
                'id'   => $this->brand->uuid,
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
            ]),
            'images'       => $this->whenLoaded('images', fn() => $this->images->map(fn($gambar) => [
                'id'         => $gambar->uuid,
                'url'        => $disk->url($gambar->path),
                'sort_order' => (int) $gambar->sort_order,
            ])->values()),
            'variants'     => $this->whenLoaded('variants', fn() => SellerVariantResource::collection($this->variants)),
            'price_min'    => (int) $this->price_min,
            'price_max'    => (int) $this->price_max,
            'total_stock'  => $this->whenLoaded('variants', fn() => (int) $this->variants->where('is_active', true)->sum('stock')),
            'sold_count'   => (int) $this->sold_count,
            'rating_avg'   => (float) $this->rating_avg,
            'rating_count' => (int) $this->rating_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];
    }
}
