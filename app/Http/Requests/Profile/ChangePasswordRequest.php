<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            // max:72 karena bcrypt hanya membaca 72 byte pertama.
            'password'         => [
                'required',
                'string',
                'confirmed',
                'max:72',
                'different:current_password',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Password saat ini wajib diisi.',
            'password.required'         => 'Password baru wajib diisi.',
            'password.confirmed'        => 'Konfirmasi password tidak cocok.',
            'password.max'              => 'Password maksimal 72 karakter.',
            'password.different'        => 'Password baru harus berbeda dari password saat ini.',
        ];
    }
}
