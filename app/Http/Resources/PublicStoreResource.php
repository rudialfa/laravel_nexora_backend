<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk toko untuk PUBLIK.
 * Sengaja TIDAK memuat: telepon, alamat lengkap, koordinat, kode area Biteship,
 * pemilik, komisi, status, dan id numerik.
 *
 * @mixin \App\Models\Store
 */
class PublicStoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $disk = Storage::disk('public');

        return [
            'id'              => $this->uuid,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'description'     => $this->description,
            'logo_url'        => $this->logo_path ? $disk->url($this->logo_path) : null,
            'banner_url'      => $this->banner_path ? $disk->url($this->banner_path) : null,
            'city'            => $this->city,
            'province'        => $this->province,
            'is_open'         => (bool) $this->is_open,
            'closed_note'     => $this->closed_note,
            'rating_avg'      => (float) $this->rating_avg,
            'rating_count'    => (int) $this->rating_count,
            'followers_count' => (int) $this->followers_count,
            'products_count'  => (int) $this->products_count,
            'operating_hours' => $this->operatingHours->sortBy('day_of_week')->values()->map(fn($hari) => [
                'day_of_week' => (int) $hari->day_of_week,
                'is_closed'   => (bool) $hari->is_closed,
                'open_time'   => $hari->open_time ? substr($hari->open_time, 0, 5) : null,
                'close_time'  => $hari->close_time ? substr($hari->close_time, 0, 5) : null,
            ]),
            'joined_at'       => ($this->verified_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
