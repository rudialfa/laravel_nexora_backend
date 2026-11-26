<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating'      => ['nullable', 'integer', 'between:1,5'],
            'with_photos' => ['nullable', 'boolean'],
            'replied'     => ['nullable', 'boolean'],
            'sort'        => ['nullable', Rule::in(['newest', 'highest', 'lowest'])],
            'per_page'    => ['nullable', 'integer', 'between:1,50'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ];
    }
}
