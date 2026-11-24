<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class PreviewCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'address_id'            => ['required', 'uuid'],
            'voucher_code'          => ['nullable', 'string', 'max:40'],
            'stores'                => ['required', 'array', 'min:1', 'max:10'],
            'stores.*.store_id'     => ['required', 'uuid', 'distinct'],
            'stores.*.courier_code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'stores.*.service_code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'stores.*.voucher_code' => ['nullable', 'string', 'max:40'],
            'stores.*.note'         => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'address_id.required'            => 'Alamat tujuan wajib dipilih.',
            'address_id.uuid'                => 'Alamat tujuan tidak valid.',
            'voucher_code.max'               => 'Kode voucher maksimal 40 karakter.',
            'stores.required'                => 'Pilih minimal satu toko untuk checkout.',
            'stores.min'                     => 'Pilih minimal satu toko untuk checkout.',
            'stores.max'                     => 'Maksimal 10 toko dalam satu checkout.',
            'stores.*.store_id.required'     => 'Toko wajib dipilih.',
            'stores.*.store_id.uuid'         => 'Toko tidak valid.',
            'stores.*.store_id.distinct'     => 'Toko tidak boleh kembar.',
            'stores.*.courier_code.required' => 'Kurir wajib dipilih.',
            'stores.*.courier_code.regex'    => 'Kode kurir tidak valid.',
            'stores.*.service_code.required' => 'Layanan pengiriman wajib dipilih.',
            'stores.*.service_code.regex'    => 'Kode layanan tidak valid.',
            'stores.*.voucher_code.max'      => 'Kode voucher maksimal 40 karakter.',
            'stores.*.note.max'              => 'Catatan maksimal 500 karakter.',
        ];
    }
}
