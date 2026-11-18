<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk kategori untuk PUBLIK. Sengaja TIDAK memuat: id numerik, status aktif, jumlah produk.
 *
 * @mixin \App\Models\Category
 */
class PublicCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $disk = Storage::disk('public');

        return [
            'id'         => $this->uuid,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'icon_url'   => $this->icon_path ? $disk->url($this->icon_path) : null,
            'image_url'  => $this->image_path ? $disk->url($this->image_path) : null,
            'sort_order' => (int) $this->sort_order,
            'parent'     => $this->whenLoaded('parent', fn() => [
                'id'   => $this->parent->uuid,
                'name' => $this->parent->name,
                'slug' => $this->parent->slug,
            ]),
            // static::collection memakai kelas turunan, sehingga versi admin juga menurun ke subkategori.
            'children'   => $this->whenLoaded('children', fn() => static::collection($this->children)),
        ];
    }
}
