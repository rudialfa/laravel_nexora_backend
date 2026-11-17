<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email:rfc', 'max:255'],
            // max:72 karena bcrypt hanya membaca 72 byte pertama.
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(8)->letters()->numbers()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'token.required'     => 'Token reset password wajib diisi.',
            'email.required'     => 'Email wajib diisi.',
            'email.email'        => 'Format email tidak valid.',
            'password.required'  => 'Password baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.max'       => 'Password maksimal 72 karakter.',
        ];
    }
}
