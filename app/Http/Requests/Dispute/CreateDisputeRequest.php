<?php

namespace App\Http\Requests\Dispute;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code'      => ['required', Rule::in(['not_received', 'damaged', 'wrong_item', 'not_as_described', 'other'])],
            'description'      => ['required', 'string', 'min:10', 'max:1000'],
            'requested_amount' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'photos'           => ['sometimes', 'array', 'max:5'],
            'photos.*'         => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason_code.required'      => 'Alasan komplain wajib dipilih.',
            'reason_code.in'            => 'Alasan komplain tidak valid.',
            'description.required'      => 'Uraian komplain wajib diisi.',
            'description.min'           => 'Uraian komplain minimal 10 karakter.',
            'description.max'           => 'Uraian komplain maksimal 1.000 karakter.',
            'requested_amount.integer'  => 'Jumlah yang diminta harus berupa bilangan bulat.',
            'requested_amount.min'      => 'Jumlah yang diminta minimal Rp1.',
            'photos.max'                => 'Maksimal 5 foto bukti.',
            'photos.*.image'            => 'Berkas harus berupa gambar.',
            'photos.*.mimes'            => 'Foto harus berformat jpg, png, atau webp.',
            'photos.*.max'              => 'Ukuran foto maksimal 5 MB.',
            'photos.*.dimensions'       => 'Dimensi foto terlalu besar.',
        ];
    }
}
