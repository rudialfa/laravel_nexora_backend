<?php

namespace App\Http\Requests\Dispute;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRefundsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status'   => ['nullable', Rule::in(['pending', 'completed', 'failed'])],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ];
    }
}
