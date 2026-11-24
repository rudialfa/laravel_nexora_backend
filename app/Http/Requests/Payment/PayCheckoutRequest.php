<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in(['bank_transfer', 'qris'])],
            'bank'   => ['nullable', 'required_if:method,bank_transfer', 'prohibited_if:method,qris', Rule::in((array) config('midtrans.banks'))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'method.required'     => 'Metode pembayaran wajib dipilih.',
            'method.in'           => 'Metode pembayaran harus bank_transfer atau qris.',
            'bank.required_if'    => 'Bank wajib dipilih untuk transfer bank.',
            'bank.prohibited_if'  => 'Bank hanya untuk metode transfer bank.',
            'bank.in'             => 'Bank tidak tersedia.',
        ];
    }
}
