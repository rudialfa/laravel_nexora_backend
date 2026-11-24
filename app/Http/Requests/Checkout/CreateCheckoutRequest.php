<?php

namespace App\Http\Requests\Checkout;

class CreateCheckoutRequest extends PreviewCheckoutRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'idempotency_key' => ['required', 'uuid'],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'idempotency_key.required' => 'Kunci permintaan wajib dikirim.',
            'idempotency_key.uuid'     => 'Kunci permintaan tidak valid.',
        ]);
    }
}
