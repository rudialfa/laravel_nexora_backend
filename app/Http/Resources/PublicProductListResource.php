<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk produk untuk DAFTAR publik (ringkas).
 * Sengaja TIDAK memuat: status, varian dan SKU, id numerik, serta data internal toko.
 * Relasi category, store, dan images harus sudah dimuat.
 *
 * @mixin \App\Models\Product
 */
class PublicProductListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $gambar = $this->images->first();

        return [
            'id'           => $this->uuid,
            'name'         => $this->name,
            'slug'         => $this->slug,
            'image_url'    => $gambar ? Storage::disk('public')->url($gambar->path) : null,
            'price_min'    => (int) $this->price_min,
            'price_max'    => (int) $this->price_max,
            'rating_avg'   => (float) $this->rating_avg,
            'rating_count' => (int) $this->rating_count,
            'sold_count'   => (int) $this->sold_count,
            'category'     => [
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ],
            'store'        => [
                'name' => $this->store->name,
                'slug' => $this->store->slug,
                'city' => $this->store->city,
            ],
        ];
    }
}
