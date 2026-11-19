<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk banner beranda untuk PUBLIK. Sengaja TIDAK memuat: id numerik, status aktif, urutan, masa tayang.
 *
 * @mixin \App\Models\Banner
 */
class PublicBannerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'title'      => $this->title,
            'image_url'  => Storage::disk('public')->url($this->image_path),
            'link_type'  => $this->link_type,
            'link_value' => $this->link_value,
        ];
    }
}
