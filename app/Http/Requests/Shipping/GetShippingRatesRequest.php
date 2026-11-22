<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

class GetShippingRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'store_id'   => ['required', 'uuid'],
            'address_id' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'store_id.required'   => 'Toko wajib dipilih.',
            'store_id.uuid'       => 'Toko tidak valid.',
            'address_id.required' => 'Alamat tujuan wajib dipilih.',
            'address_id.uuid'     => 'Alamat tujuan tidak valid.',
        ];
    }
}
