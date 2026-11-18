<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class BankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('bank_code'))) {
            $merge['bank_code'] = Str::lower(trim($this->input('bank_code')));
        }

        // Spasi dan tanda hubung dibuang; huruf tetap ditolak oleh aturan di bawah.
        if (is_string($this->input('account_number'))) {
            $merge['account_number'] = preg_replace('/[\s\-]/', '', $this->input('account_number'));
        }

        $this->merge($merge);
    }

    /**
     * Kode bank hanya diperiksa bentuknya. Daftar kode bank yang sah dicocokkan di modul penarikan dana.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_code'      => ['required', 'string', 'regex:/^[a-z0-9_]{2,20}$/'],
            'bank_name'      => ['required', 'string', 'min:2', 'max:100'],
            'account_name'   => ['required', 'string', 'min:2', 'max:100'],
            'account_number' => ['required', 'string', 'regex:/^\d{6,20}$/'],
            'is_default'     => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bank_code.required'      => 'Kode bank wajib diisi.',
            'bank_code.regex'         => 'Kode bank hanya boleh huruf kecil, angka, dan garis bawah (2-20 karakter).',
            'bank_name.required'      => 'Nama bank wajib diisi.',
            'account_name.required'   => 'Nama pemilik rekening wajib diisi.',
            'account_number.required' => 'Nomor rekening wajib diisi.',
            'account_number.regex'    => 'Nomor rekening harus 6 sampai 20 digit angka.',
        ];
    }
}
