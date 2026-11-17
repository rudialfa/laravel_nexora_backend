<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk pengajuan toko yang boleh dilihat PEMOHON.
 * Sengaja TIDAK memuat: NIK, hash NIK, jalur foto, id numerik, user_id.
 *
 * @mixin \App\Models\StoreApplication
 */
class StoreApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->uuid,
            'status'           => $this->status,
            'store_name'       => $this->store_name,
            'description'      => $this->description,
            'phone'            => $this->phone,
            'province'         => $this->province,
            'city'             => $this->city,
            'district'         => $this->district,
            'village'          => $this->village,
            'postal_code'      => $this->postal_code,
            'street'           => $this->street,
            'id_holder_name'   => $this->id_holder_name,
            'has_selfie'       => $this->selfie_path !== null,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at'      => $this->reviewed_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
            'store'            => $this->store
                ? ['id' => $this->store->uuid, 'name' => $this->store->name, 'slug' => $this->store->slug]
                : null,
        ];
    }
}
