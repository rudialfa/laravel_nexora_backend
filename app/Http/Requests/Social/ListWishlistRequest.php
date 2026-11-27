<?php

namespace App\Http\Requests\Social;

use Illuminate\Foundation\Http\FormRequest;

class ListWishlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q'        => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
