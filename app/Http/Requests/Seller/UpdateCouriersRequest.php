<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateCouriersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Kode kurir dijadikan huruf kecil SEBELUM validasi, sehingga "JNE" dan "jne" terdeteksi kembar. */
    protected function prepareForValidation(): void
    {
        $couriers = $this->input('couriers');

        if (! is_array($couriers)) {
            return;
        }

        $this->merge(['couriers' => array_map(function ($kurir) {
            if (is_array($kurir) && isset($kurir['code']) && is_string($kurir['code'])) {
                $kurir['code'] = Str::lower(trim($kurir['code']));
            }

            return $kurir;
        }, $couriers)]);
    }

    /**
     * Bentuk kode saja yang diperiksa. Daftar kode kurir yang sah menurut Biteship
     * dicocokkan di modul pengiriman, bukan di sini.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'couriers'             => ['present', 'array', 'max:30'],
            'couriers.*.code'      => ['required', 'string', 'regex:/^[a-z0-9_]{2,30}$/', 'distinct'],
            'couriers.*.is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'couriers.present'             => 'Daftar kurir wajib dikirim (boleh kosong).',
            'couriers.max'                 => 'Maksimal 30 kurir.',
            'couriers.*.code.required'     => 'Kode kurir wajib diisi.',
            'couriers.*.code.regex'        => 'Kode kurir hanya boleh huruf kecil, angka, dan garis bawah (2-30 karakter).',
            'couriers.*.code.distinct'     => 'Kode kurir tidak boleh kembar.',
            'couriers.*.is_active.required' => 'is_active wajib diisi.',
        ];
    }
}
