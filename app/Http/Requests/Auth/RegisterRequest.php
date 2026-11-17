<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Email dijadikan huruf kecil dan telepon dinormalkan SEBELUM validasi (termasuk cek unik). */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('email'))) {
            $merge['email'] = Str::lower(trim($this->input('email')));
        }

        if (is_string($this->input('phone')) && trim($this->input('phone')) !== '') {
            $merge['phone'] = PhoneNumber::normalize($this->input('phone')) ?? trim($this->input('phone'));
        }

        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'min:2', 'max:100'],
            'email'       => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone'       => ['nullable', 'string', 'regex:/^628\d{7,11}$/', 'unique:users,phone'],
            // max:72 karena bcrypt hanya membaca 72 byte pertama.
            'password'    => ['required', 'string', 'confirmed', 'max:72', Password::min(8)->letters()->numbers()],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required'      => 'Nama wajib diisi.',
            'name.min'           => 'Nama minimal 2 karakter.',
            'email.required'     => 'Email wajib diisi.',
            'email.email'        => 'Format email tidak valid.',
            'email.unique'       => 'Email sudah terdaftar.',
            'phone.regex'        => 'Nomor telepon tidak valid. Gunakan nomor seluler Indonesia, contoh 081234567890.',
            'phone.unique'       => 'Nomor telepon sudah terdaftar.',
            'password.required'  => 'Password wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.max'       => 'Password maksimal 72 karakter.',
        ];
    }
}
