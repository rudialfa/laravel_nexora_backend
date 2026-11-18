<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class CatalogImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * SVG sengaja tidak diizinkan (bisa memuat skrip). Gambar kategori maksimal 2 MB;
     * ikon kategori dan logo brand maksimal 1 MB. Dimensi maksimal 4096x4096 menjaga
     * memori server saat gambar diproses ulang.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maksKb = $this->route('type') === 'image' ? 2048 : 1024;

        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                "max:{$maksKb}",
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'image.required'   => 'Gambar wajib diunggah.',
            'image.image'      => 'File harus berupa gambar.',
            'image.mimes'      => 'Format gambar harus JPG, PNG, atau WEBP.',
            'image.max'        => 'Ukuran gambar melebihi batas (gambar kategori maksimal 2 MB; ikon dan logo maksimal 1 MB).',
            'image.dimensions' => 'Dimensi gambar maksimal 4096 x 4096 piksel.',
        ];
    }
}
