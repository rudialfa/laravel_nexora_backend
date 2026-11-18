<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk toko yang dilihat PEMILIKNYA.
 * Sengaja TIDAK memuat: id numerik, owner_id, deleted_at.
 *
 * @mixin \App\Models\Store
 */
class SellerStoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $disk = Storage::disk('public');

        return [
            'id'               => $this->uuid,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'description'      => $this->description,
            'logo_url'         => $this->logo_path ? $disk->url($this->logo_path) : null,
            'banner_url'       => $this->banner_path ? $disk->url($this->banner_path) : null,
            'phone'            => $this->phone,
            'province'         => $this->province,
            'city'             => $this->city,
            'district'         => $this->district,
            'village'          => $this->village,
            'postal_code'      => $this->postal_code,
            'street'           => $this->street,
            'latitude'         => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude'        => $this->longitude !== null ? (float) $this->longitude : null,
            'biteship_area_id' => $this->biteship_area_id,
            'timezone'         => $this->timezone,
            'status'           => $this->status,
            'is_open'          => (bool) $this->is_open,
            'closed_note'      => $this->closed_note,
            'rating_avg'       => (float) $this->rating_avg,
            'rating_count'     => (int) $this->rating_count,
            'followers_count'  => (int) $this->followers_count,
            'verified_at'      => $this->verified_at?->toIso8601String(),
            'operating_hours'  => $this->operatingHours->sortBy('day_of_week')->values()->map(fn($hari) => [
                'day_of_week' => (int) $hari->day_of_week,
                'is_closed'   => (bool) $hari->is_closed,
                'open_time'   => $hari->open_time ? substr($hari->open_time, 0, 5) : null,
                'close_time'  => $hari->close_time ? substr($hari->close_time, 0, 5) : null,
            ]),
            'couriers'         => $this->courierSettings->sortBy('courier_code')->values()->map(fn($kurir) => [
                'code'      => $kurir->courier_code,
                'is_active' => (bool) $kurir->is_active,
            ]),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
