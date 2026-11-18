<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk rekening bank toko. Nomor rekening TIDAK pernah dikirim; hanya 4 digit terakhir.
 *
 * @mixin \App\Models\StoreBankAccount
 */
class BankAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->uuid,
            'bank_code'             => $this->bank_code,
            'bank_name'             => $this->bank_name,
            'account_name'          => $this->account_name,
            'account_number_masked' => '****' . $this->account_number_last4,
            'is_default'            => (bool) $this->is_default,
            'created_at'            => $this->created_at?->toIso8601String(),
        ];
    }
}
