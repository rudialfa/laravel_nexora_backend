<?php

namespace App\Http\Requests\StoreApplication;

use Illuminate\Foundation\Http\FormRequest;

class ListStoreApplicationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status'   => ['nullable', 'in:pending,approved,rejected'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in'    => 'Status harus pending, approved, atau rejected.',
            'per_page.max' => 'per_page maksimal 50.',
        ];
    }
}
