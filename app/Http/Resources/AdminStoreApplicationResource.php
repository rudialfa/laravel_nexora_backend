<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Bentuk pengajuan toko untuk ADMIN: sama seperti untuk pemohon, ditambah data pemohon.
 * NIK tidak ada di sini; NIK hanya ditambahkan di endpoint detail (dan tercatat di audit log).
 *
 * @mixin \App\Models\StoreApplication
 */
class AdminStoreApplicationResource extends StoreApplicationResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'applicant' => $this->user
                ? [
                    'id'    => $this->user->uuid,
                    'name'  => $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                ]
                : null,
        ];
    }
}
