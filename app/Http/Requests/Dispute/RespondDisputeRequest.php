<?php

namespace App\Http\Requests\Dispute;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['agree', 'reject'])],
            'response' => ['required', 'string', 'min:5', 'max:1000'],
            'photos'   => ['sometimes', 'array', 'max:3'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan wajib dipilih.',
            'decision.in'       => 'Keputusan harus agree atau reject.',
            'response.required' => 'Penjelasan wajib diisi.',
            'response.min'      => 'Penjelasan minimal 5 karakter.',
            'response.max'      => 'Penjelasan maksimal 1.000 karakter.',
            'photos.max'        => 'Maksimal 3 foto.',
            'photos.*.image'    => 'Berkas harus berupa gambar.',
            'photos.*.mimes'    => 'Foto harus berformat jpg, png, atau webp.',
            'photos.*.max'      => 'Ukuran foto maksimal 5 MB.',
        ];
    }
}
