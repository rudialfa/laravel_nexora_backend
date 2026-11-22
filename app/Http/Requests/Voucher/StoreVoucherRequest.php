<?php

namespace App\Http\Requests\Voucher;

use App\Models\Voucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Kode selalu huruf besar dan tanpa spasi di tepi, sebelum diperiksa keunikannya. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $batasNilai = $this->input('discount_type') === Voucher::TYPE_PERCENTAGE ? 100 : 1000000000;

        return [
            'code'           => ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('vouchers', 'code')],
            'name'           => ['required', 'string', 'min:3', 'max:100'],
            'description'    => ['nullable', 'string', 'max:500'],
            'discount_type'  => ['required', Rule::in([Voucher::TYPE_PERCENTAGE, Voucher::TYPE_FIXED])],
            'discount_value' => ['required', 'integer', 'min:1', 'max:' . $batasNilai],
            'max_discount'   => ['nullable', 'integer', 'min:1', 'max:1000000000', 'prohibited_if:discount_type,' . Voucher::TYPE_FIXED],
            'min_purchase'   => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'quota'          => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'per_user_limit' => ['sometimes', 'integer', 'between:1,100'],
            'starts_at'      => ['nullable', 'date'],
            'ends_at'        => ['nullable', 'date', 'after:now'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required'                => 'Kode voucher wajib diisi.',
            'code.min'                     => 'Kode voucher minimal 4 karakter.',
            'code.max'                     => 'Kode voucher maksimal 40 karakter.',
            'code.regex'                   => 'Kode voucher hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
            'code.unique'                  => 'Kode voucher sudah dipakai.',
            'name.required'                => 'Nama voucher wajib diisi.',
            'discount_type.required'       => 'Jenis diskon wajib dipilih.',
            'discount_type.in'             => 'Jenis diskon harus percentage atau fixed.',
            'discount_value.required'      => 'Nilai diskon wajib diisi.',
            'discount_value.integer'       => 'Nilai diskon harus berupa bilangan bulat.',
            'discount_value.min'           => 'Nilai diskon minimal 1.',
            'discount_value.max'           => 'Nilai diskon melebihi batas (persen maksimal 100).',
            'max_discount.prohibited_if'   => 'Batas potongan hanya untuk diskon persen.',
            'quota.min'                    => 'Kuota minimal 1.',
            'per_user_limit.between'       => 'Batas per pengguna harus di antara 1 dan 100.',
            'ends_at.after'                => 'Waktu berakhir harus di masa depan.',
        ];
    }
}
