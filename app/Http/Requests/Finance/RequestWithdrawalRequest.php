<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class RequestWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'uuid'],
            'amount'          => ['required', 'integer', 'min:1', 'max:1000000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bank_account_id.required' => 'Rekening tujuan wajib dipilih.',
            'bank_account_id.uuid'     => 'Rekening tujuan tidak valid.',
            'amount.required'          => 'Jumlah penarikan wajib diisi.',
            'amount.integer'           => 'Jumlah penarikan harus berupa bilangan bulat.',
            'amount.min'               => 'Jumlah penarikan tidak valid.',
            'amount.max'               => 'Jumlah penarikan terlalu besar.',
        ];
    }
}
