<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk produk untuk PUBLIK. Sengaja TIDAK memuat: status, id numerik, data internal toko
 * (telepon, alamat lengkap, pemilik, komisi).
 *
 * @mixin \App\Models\Product
 */
class PublicProductResource extends JsonResource
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
            'variants'     => $this->whenLoaded('variants', fn() => PublicVariantResource::collection($this->variants)),
            'price_min'    => (int) $this->price_min,
            'price_max'    => (int) $this->price_max,
            'rating_avg'   => (float) $this->rating_avg,
            'rating_count' => (int) $this->rating_count,
            'sold_count'   => (int) $this->sold_count,
            'store'        => $this->whenLoaded('store', fn() => [
                'id'           => $this->store->uuid,
                'name'         => $this->store->name,
                'slug'         => $this->store->slug,
                'city'         => $this->store->city,
                'province'     => $this->store->province,
                'logo_url'     => $this->store->logo_path ? $disk->url($this->store->logo_path) : null,
                'is_open'      => (bool) $this->store->is_open,
                'closed_note'  => $this->store->closed_note,
                'rating_avg'   => (float) $this->store->rating_avg,
                'rating_count' => (int) $this->store->rating_count,
            ]),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
