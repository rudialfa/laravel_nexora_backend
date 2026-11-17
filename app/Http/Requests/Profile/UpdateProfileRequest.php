<?php

namespace App\Http\Requests\Profile;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Telepon dinormalkan SEBELUM validasi (termasuk cek unik). */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone) && trim($phone) !== '') {
            $this->merge(['phone' => PhoneNumber::normalize($phone) ?? trim($phone)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'       => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'phone'      => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^628\d{7,11}$/',
                Rule::unique('users', 'phone')->ignore($this->user()->id),
            ],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'gender'     => ['sometimes', 'nullable', 'in:male,female'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required'          => 'Nama wajib diisi.',
            'name.min'               => 'Nama minimal 2 karakter.',
            'phone.regex'            => 'Nomor telepon tidak valid. Gunakan nomor seluler Indonesia, contoh 081234567890.',
            'phone.unique'           => 'Nomor telepon sudah dipakai akun lain.',
            'birth_date.date_format' => 'Tanggal lahir harus berformat YYYY-MM-DD.',
            'birth_date.before'      => 'Tanggal lahir harus sebelum hari ini.',
            'birth_date.after'       => 'Tanggal lahir tidak valid.',
            'gender.in'              => 'Gender harus male atau female.',
        ];
    }
}
