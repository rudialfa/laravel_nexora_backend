<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class ProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * SVG sengaja tidak diizinkan (bisa memuat skrip). Dimensi maksimal 4096x4096 menjaga
     * memori server saat gambar diproses ulang.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required'   => 'Foto wajib diunggah.',
            'image.image'      => 'File harus berupa gambar.',
            'image.mimes'      => 'Format foto harus JPG, PNG, atau WEBP.',
            'image.max'        => 'Ukuran foto maksimal 5 MB.',
            'image.dimensions' => 'Dimensi foto maksimal 4096 x 4096 piksel.',
        ];
    }
}
