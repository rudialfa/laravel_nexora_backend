<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Slug dijadikan huruf kecil SEBELUM validasi, sehingga "Fashion" dan "fashion" terdeteksi kembar. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => Str::lower(trim($this->input('slug')))]);
        }
    }

    /**
     * POST (tambah): nama wajib. PATCH (ubah): semua kolom opsional.
     * Cek slug kembar mencakup kategori yang sudah dihapus (slugnya tetap terkunci).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $wajib   = $this->isMethod('POST') ? 'required' : 'sometimes';
        $abaikan = $this->route('category');   // UUID saat PATCH, null saat POST

        return [
            'name'       => [$wajib, 'string', 'min:2', 'max:100'],
            'slug'       => [
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->ignore($abaikan, 'uuid'),
            ],
            'parent_id'  => ['nullable', 'uuid'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required'     => 'Nama kategori wajib diisi.',
            'name.min'          => 'Nama kategori minimal 2 karakter.',
            'slug.regex'        => 'Slug hanya boleh huruf kecil, angka, dan tanda hubung (contoh: perlengkapan-rumah).',
            'slug.unique'       => 'Slug sudah dipakai.',
            'parent_id.uuid'    => 'Kategori induk tidak valid.',
            'sort_order.between' => 'Urutan harus di antara 0 dan 65535.',
            'is_active.boolean' => 'is_active harus true atau false.',
        ];
    }
}
