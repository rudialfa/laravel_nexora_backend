<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk brand untuk PUBLIK. Sengaja TIDAK memuat: id numerik, status aktif.
 *
 * @mixin \App\Models\Brand
 */
class PublicBrandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->uuid,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
        ];
    }
}
