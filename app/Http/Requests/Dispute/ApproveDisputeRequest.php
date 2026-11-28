<?php

namespace App\Http\Requests\Dispute;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount'              => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'note'                => ['nullable', 'string', 'max:500'],
            'bearer'              => ['nullable', Rule::in(['seller', 'platform'])],
            'include_service_fee' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.integer'              => 'Jumlah pengembalian harus berupa bilangan bulat.',
            'amount.min'                  => 'Jumlah pengembalian minimal Rp1.',
            'note.max'                    => 'Catatan maksimal 500 karakter.',
            'bearer.in'                   => 'Penanggung harus seller atau platform.',
            'include_service_fee.boolean' => 'include_service_fee harus true atau false.',
        ];
    }
}
