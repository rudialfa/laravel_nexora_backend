<?php

namespace App\Http\Requests\Social;

use Illuminate\Foundation\Http\FormRequest;

class AddWishlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Produk wajib dipilih.',
            'product_id.uuid'     => 'Produk tidak valid.',
        ];
    }
}
