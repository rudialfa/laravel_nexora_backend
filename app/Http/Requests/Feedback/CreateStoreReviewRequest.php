<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;

class CreateStoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating'  => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rating.required' => 'Rating wajib diisi.',
            'rating.integer'  => 'Rating harus berupa bilangan bulat.',
            'rating.between'  => 'Rating harus di antara 1 dan 5.',
            'comment.max'     => 'Komentar maksimal 1.000 karakter.',
        ];
    }
}
