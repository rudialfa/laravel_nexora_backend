<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;

class CreateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'variant_id'   => ['required', 'uuid'],
            'rating'       => ['required', 'integer', 'between:1,5'],
            'comment'      => ['nullable', 'string', 'max:1000'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'photos'       => ['sometimes', 'array', 'max:5'],
            'photos.*'     => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'variant_id.required' => 'Produk yang dinilai wajib dipilih.',
            'variant_id.uuid'     => 'Produk yang dinilai tidak valid.',
            'rating.required'     => 'Rating wajib diisi.',
            'rating.integer'      => 'Rating harus berupa bilangan bulat.',
            'rating.between'      => 'Rating harus di antara 1 dan 5.',
            'comment.max'         => 'Komentar maksimal 1.000 karakter.',
            'is_anonymous.boolean' => 'is_anonymous harus true atau false.',
            'photos.array'        => 'Foto harus berupa daftar berkas.',
            'photos.max'          => 'Maksimal 5 foto per ulasan.',
            'photos.*.image'      => 'Berkas harus berupa gambar.',
            'photos.*.mimes'      => 'Foto harus berformat jpg, png, atau webp.',
            'photos.*.max'        => 'Ukuran foto maksimal 5 MB.',
            'photos.*.dimensions' => 'Dimensi foto terlalu besar.',
        ];
    }
}
