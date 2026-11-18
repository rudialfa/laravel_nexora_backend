<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => Str::lower(trim($this->input('slug')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $wajib   = $this->isMethod('POST') ? 'required' : 'sometimes';
        $abaikan = $this->route('brand');   // UUID saat PATCH, null saat POST

        return [
            'name'      => [$wajib, 'string', 'min:2', 'max:100', Rule::unique('brands', 'name')->ignore($abaikan, 'uuid')],
            'slug'      => [
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('brands', 'slug')->ignore($abaikan, 'uuid'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required'     => 'Nama brand wajib diisi.',
            'name.min'          => 'Nama brand minimal 2 karakter.',
            'name.unique'       => 'Nama brand sudah dipakai.',
            'slug.regex'        => 'Slug hanya boleh huruf kecil, angka, dan tanda hubung (contoh: nexora-wear).',
            'slug.unique'       => 'Slug sudah dipakai.',
            'is_active.boolean' => 'is_active harus true atau false.',
        ];
    }
}
