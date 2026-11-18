<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class StoreImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * SVG sengaja tidak diizinkan (bisa memuat skrip). Logo maksimal 2 MB, banner 4 MB;
     * dimensi maksimal 4096x4096 menjaga memori server saat gambar diproses ulang.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maksKb = $this->route('type') === 'logo' ? 2048 : 4096;

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
            'image.max'        => 'Ukuran gambar melebihi batas (logo maksimal 2 MB, banner maksimal 4 MB).',
            'image.dimensions' => 'Dimensi gambar maksimal 4096 x 4096 piksel.',
        ];
    }
}
