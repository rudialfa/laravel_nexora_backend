<?php

namespace App\Http\Requests\Cart;

use App\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Minimal salah satu dari quantity atau is_selected harus dikirim. */
    public function rules(): array
    {
        return [
            'quantity'    => ['required_without:is_selected', 'integer', 'between:1,' . CartService::MAX_QUANTITY],
            'is_selected' => ['required_without:quantity', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'quantity.required_without'    => 'Kirim quantity atau is_selected.',
            'quantity.integer'             => 'Jumlah harus berupa bilangan bulat.',
            'quantity.between'             => 'Jumlah harus di antara 1 dan ' . CartService::MAX_QUANTITY . '.',
            'is_selected.required_without' => 'Kirim quantity atau is_selected.',
            'is_selected.boolean'          => 'is_selected harus true atau false.',
        ];
    }
}
