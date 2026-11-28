<?php

namespace App\Http\Requests\Dispute;

use Illuminate\Foundation\Http\FormRequest;

class CompleteRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference.required' => 'Nomor referensi pengembalian wajib diisi.',
            'reference.min'      => 'Nomor referensi minimal 3 karakter.',
            'reference.max'      => 'Nomor referensi maksimal 100 karakter.',
        ];
    }
}
