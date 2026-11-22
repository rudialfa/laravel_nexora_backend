<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListVouchersRequest extends FormRequest
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
            'status'   => ['nullable', Rule::in(['active', 'inactive', 'scheduled', 'expired', 'exhausted'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
