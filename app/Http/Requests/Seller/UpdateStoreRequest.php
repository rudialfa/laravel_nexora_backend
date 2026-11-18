<?php

namespace App\Http\Requests\Seller;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone) && trim($phone) !== '') {
            $this->merge(['phone' => PhoneNumber::normalize($phone) ?? trim($phone)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'             => ['sometimes', 'required', 'string', 'min:3', 'max:100'],
            'description'      => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone'            => ['sometimes', 'required', 'string', 'regex:/^628\d{7,11}$/'],
            'province'         => ['sometimes', 'required', 'string', 'max:100'],
            'city'             => ['sometimes', 'required', 'string', 'max:100'],
            'district'         => ['sometimes', 'required', 'string', 'max:100'],
            'village'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code'      => ['sometimes', 'required', 'string', 'regex:/^\d{5}$/'],
            'street'           => ['sometimes', 'required', 'string', 'min:5', 'max:500'],
            'latitude'         => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude'        => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            // Nilainya nanti dipilih dari pencarian area Biteship di modul pengiriman.
            'biteship_area_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'timezone'         => ['sometimes', 'required', 'in:Asia/Jakarta,Asia/Makassar,Asia/Jayapura'],
            'is_open'          => ['sometimes', 'required', 'boolean'],
            'closed_note'      => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required'           => 'Nama toko wajib diisi.',
            'name.min'                => 'Nama toko minimal 3 karakter.',
            'phone.required'          => 'Nomor telepon toko wajib diisi.',
            'phone.regex'             => 'Nomor telepon tidak valid. Gunakan nomor seluler Indonesia, contoh 081234567890.',
            'province.required'       => 'Provinsi wajib diisi.',
            'city.required'           => 'Kota/kabupaten wajib diisi.',
            'district.required'       => 'Kecamatan wajib diisi.',
            'postal_code.required'    => 'Kode pos wajib diisi.',
            'postal_code.regex'       => 'Kode pos harus 5 digit angka.',
            'street.required'         => 'Alamat lengkap wajib diisi.',
            'street.min'              => 'Alamat lengkap minimal 5 karakter.',
            'latitude.between'        => 'Latitude harus di antara -90 dan 90.',
            'longitude.between'       => 'Longitude harus di antara -180 dan 180.',
            'latitude.required_with'  => 'Latitude wajib diisi jika longitude diisi.',
            'longitude.required_with' => 'Longitude wajib diisi jika latitude diisi.',
            'timezone.in'             => 'Zona waktu harus Asia/Jakarta, Asia/Makassar, atau Asia/Jayapura.',
            'is_open.boolean'         => 'is_open harus true atau false.',
        ];
    }
}
