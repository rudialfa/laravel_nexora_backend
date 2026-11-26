<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;

class ReplyReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reply' => ['required', 'string', 'min:2', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reply.required' => 'Balasan wajib diisi.',
            'reply.min'      => 'Balasan minimal 2 karakter.',
            'reply.max'      => 'Balasan maksimal 500 karakter.',
        ];
    }
}
