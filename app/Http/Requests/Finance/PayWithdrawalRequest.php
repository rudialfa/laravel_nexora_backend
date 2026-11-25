<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class PayWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'transfer_reference' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'transfer_reference.required' => 'Nomor referensi transfer wajib diisi.',
            'transfer_reference.min'      => 'Nomor referensi transfer minimal 3 karakter.',
            'transfer_reference.max'      => 'Nomor referensi transfer maksimal 100 karakter.',
        ];
    }
}
