<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bentuk data pengguna yang boleh keluar ke aplikasi.
 * Sengaja TIDAK memuat: id numerik, password, status, last_login_at, deleted_at.
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->uuid,
            'name'           => $this->name,
            'email'          => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'phone'          => $this->phone,
            'phone_verified' => $this->phone_verified_at !== null,
            'birth_date'     => $this->birth_date?->format('Y-m-d'),
            'gender'         => $this->gender,
            'avatar_url'     => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'roles'          => $this->getRoleNames()->values(),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
