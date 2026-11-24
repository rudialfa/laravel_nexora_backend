<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
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
            'status'   => ['nullable', Rule::in(['pending_payment', 'paid', 'processing', 'packed', 'shipped', 'in_transit', 'delivered', 'completed', 'cancelled', 'refunded'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
