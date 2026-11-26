<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;

class AnswerQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answer' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'answer.required' => 'Jawaban wajib diisi.',
            'answer.min'      => 'Jawaban minimal 2 karakter.',
            'answer.max'      => 'Jawaban maksimal 1.000 karakter.',
        ];
    }
}
