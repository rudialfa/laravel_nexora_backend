<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk varian yang dilihat SELLER (pemilik produk).
 * Sengaja TIDAK memuat: id numerik dan product_id.
 *
 * @mixin \App\Models\ProductVariant
 */
class SellerVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->uuid,
            'sku'                => $this->sku,
            'name'               => $this->name,
            'options'            => $this->options,
            'price'              => (int) $this->price,
            'discount_price'     => $this->discount_price !== null ? (int) $this->discount_price : null,
            'discount_starts_at' => $this->discount_starts_at?->toIso8601String(),
            'discount_ends_at'   => $this->discount_ends_at?->toIso8601String(),
            'effective_price'    => $this->effectivePrice(),
            'stock'              => (int) $this->stock,
            'weight_gram'        => (int) $this->weight_gram,
            'length_cm'          => $this->length_cm !== null ? (int) $this->length_cm : null,
            'width_cm'           => $this->width_cm !== null ? (int) $this->width_cm : null,
            'height_cm'          => $this->height_cm !== null ? (int) $this->height_cm : null,
            'is_active'          => (bool) $this->is_active,
            'sort_order'         => (int) $this->sort_order,
        ];
    }
}
