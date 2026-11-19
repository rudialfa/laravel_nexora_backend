<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk varian untuk PUBLIK. Sengaja TIDAK memuat: SKU, status aktif, id numerik.
 *
 * @mixin \App\Models\ProductVariant
 */
class PublicVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $akhir  = $this->effectivePrice();
        $diskon = $akhir < (int) $this->price;

        return [
            'id'               => $this->uuid,
            'name'             => $this->name,
            'options'          => $this->options,
            'price'            => (int) $this->price,
            'final_price'      => $akhir,
            'discount_active'  => $diskon,
            'discount_ends_at' => $diskon ? $this->discount_ends_at?->toIso8601String() : null,
            'stock'            => (int) $this->stock,
            'weight_gram'      => (int) $this->weight_gram,
        ];
    }
}
