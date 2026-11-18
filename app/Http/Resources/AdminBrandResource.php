<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Bentuk brand untuk ADMIN: sama seperti publik, ditambah status aktif dan jumlah produk.
 *
 * @mixin \App\Models\Brand
 */
class AdminBrandResource extends PublicBrandResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'is_active'      => (bool) $this->is_active,
            'products_count' => $this->whenCounted('products'),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
