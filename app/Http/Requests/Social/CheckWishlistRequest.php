<?php

namespace App\Http\Requests\Social;

use Illuminate\Foundation\Http\FormRequest;

class CheckWishlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'product_ids.*' => ['uuid', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_ids.required'   => 'Daftar produk wajib diisi.',
            'product_ids.array'      => 'Daftar produk tidak valid.',
            'product_ids.min'        => 'Daftar produk wajib diisi.',
            'product_ids.max'        => 'Maksimal 50 produk per permintaan.',
            'product_ids.*.uuid'     => 'Produk tidak valid.',
            'product_ids.*.distinct' => 'Produk tidak boleh kembar.',
        ];
    }
}
