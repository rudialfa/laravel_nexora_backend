<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;

class CheckVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode voucher wajib diisi.',
            'code.string'   => 'Kode voucher tidak valid.',
            'code.max'      => 'Kode voucher maksimal 40 karakter.',
        ];
    }
}
