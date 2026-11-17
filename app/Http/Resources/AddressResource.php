<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk data alamat yang boleh keluar ke aplikasi.
 * Sengaja TIDAK memuat: id numerik, user_id, deleted_at.
 *
 * @mixin \App\Models\Address
 */
class AddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->uuid,
            'label'            => $this->label,
            'recipient_name'   => $this->recipient_name,
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
            'is_default'       => (bool) $this->is_default,
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
