<?php

namespace App\Http\Requests\Cart;

use App\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'uuid'],
            'quantity'   => ['sometimes', 'integer', 'between:1,' . CartService::MAX_QUANTITY],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'variant_id.required' => 'Varian wajib dipilih.',
            'variant_id.uuid'     => 'Varian tidak valid.',
            'quantity.integer'    => 'Jumlah harus berupa bilangan bulat.',
            'quantity.between'    => 'Jumlah harus di antara 1 dan ' . CartService::MAX_QUANTITY . '.',
        ];
    }
}
