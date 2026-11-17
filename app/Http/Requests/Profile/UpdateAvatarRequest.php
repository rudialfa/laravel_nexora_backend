<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * SVG sengaja tidak diizinkan (bisa memuat skrip). Batas 4096x4096 piksel
     * menjaga memori server saat gambar dibuka untuk diproses ulang;
     * aplikasi Flutter sebaiknya memperkecil foto sebelum mengunggah.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'avatar.required'   => 'Foto wajib diunggah.',
            'avatar.image'      => 'File harus berupa gambar.',
            'avatar.mimes'      => 'Format foto harus JPG, PNG, atau WEBP.',
            'avatar.max'        => 'Ukuran foto maksimal 2 MB.',
            'avatar.dimensions' => 'Dimensi foto maksimal 4096 x 4096 piksel.',
        ];
    }
}
