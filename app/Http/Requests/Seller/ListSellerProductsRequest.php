<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class ListSellerProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status'   => ['nullable', 'in:draft,active,inactive,suspended'],
            'q'        => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in'    => 'Status harus draft, active, inactive, atau suspended.',
            'per_page.min' => 'per_page minimal 1.',
            'per_page.max' => 'per_page maksimal 50.',
        ];
    }
}
