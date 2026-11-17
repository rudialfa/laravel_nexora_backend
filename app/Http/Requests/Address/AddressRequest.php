<?php

namespace App\Http\Requests\Address;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
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

    /**
     * POST (tambah): kolom utama wajib. PATCH (ubah): semua kolom opsional.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $wajib = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'label'            => [$wajib, 'string', 'max:50'],
            'recipient_name'   => [$wajib, 'string', 'min:2', 'max:100'],
            'phone'            => [$wajib, 'string', 'regex:/^628\d{7,11}$/'],
            'province'         => [$wajib, 'string', 'max:100'],
            'city'             => [$wajib, 'string', 'max:100'],
            'district'         => [$wajib, 'string', 'max:100'],
            'village'          => ['nullable', 'string', 'max:100'],
            'postal_code'      => [$wajib, 'string', 'regex:/^\d{5}$/'],
            'street'           => [$wajib, 'string', 'min:5', 'max:500'],
            'latitude'         => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude'        => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            // Sementara diterima apa adanya; divalidasi terhadap pencarian area Biteship di modul pengiriman.
            'biteship_area_id' => ['nullable', 'string', 'max:100'],
            'is_default'       => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.required'          => 'Label alamat wajib diisi.',
            'recipient_name.required' => 'Nama penerima wajib diisi.',
            'phone.required'          => 'Nomor telepon penerima wajib diisi.',
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
        ];
    }
}
