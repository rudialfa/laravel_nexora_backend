<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListWalletTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bucket'   => ['nullable', Rule::in(['available', 'pending'])],
            'type'     => ['nullable', Rule::in(['sale_hold', 'sale_release', 'sale_reversal', 'shipping_difference', 'withdrawal', 'withdrawal_reversal', 'adjustment'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
