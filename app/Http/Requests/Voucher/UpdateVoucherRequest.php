<?php

namespace App\Http\Requests\Voucher;

use App\Models\Voucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Semua kolom opsional. Kode tidak termasuk karena tidak boleh diubah. */
    public function rules(): array
    {
        return [
            'name'           => ['sometimes', 'string', 'min:3', 'max:100'],
            'description'    => ['sometimes', 'nullable', 'string', 'max:500'],
            'discount_type'  => ['sometimes', Rule::in([Voucher::TYPE_PERCENTAGE, Voucher::TYPE_FIXED])],
            'discount_value' => ['sometimes', 'integer', 'min:1', 'max:1000000000'],
            'max_discount'   => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'min_purchase'   => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'quota'          => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'per_user_limit' => ['sometimes', 'integer', 'between:1,100'],
            'starts_at'      => ['sometimes', 'nullable', 'date'],
            'ends_at'        => ['sometimes', 'nullable', 'date'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'discount_type.in'       => 'Jenis diskon harus percentage atau fixed.',
            'discount_value.integer' => 'Nilai diskon harus berupa bilangan bulat.',
            'discount_value.min'     => 'Nilai diskon minimal 1.',
            'quota.min'              => 'Kuota minimal 1.',
            'per_user_limit.between' => 'Batas per pengguna harus di antara 1 dan 100.',
        ];
    }
}
