<?php

namespace App\Http\Requests\Dispute;

use Illuminate\Foundation\Http\FormRequest;

class FailRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:250'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan kegagalan wajib diisi.',
            'reason.min'      => 'Alasan kegagalan minimal 5 karakter.',
            'reason.max'      => 'Alasan kegagalan maksimal 250 karakter.',
        ];
    }
}
