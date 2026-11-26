<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;

class AskQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'question.required' => 'Pertanyaan wajib diisi.',
            'question.min'      => 'Pertanyaan minimal 5 karakter.',
            'question.max'      => 'Pertanyaan maksimal 500 karakter.',
        ];
    }
}
