<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

class SearchAreasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.required' => 'Kata pencarian wajib diisi.',
            'q.string'   => 'Kata pencarian tidak valid.',
            'q.min'      => 'Kata pencarian minimal 3 karakter.',
            'q.max'      => 'Kata pencarian maksimal 100 karakter.',
        ];
    }
}
