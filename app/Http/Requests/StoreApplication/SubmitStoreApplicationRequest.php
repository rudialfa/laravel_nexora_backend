<?php

namespace App\Http\Requests\StoreApplication;

use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SubmitStoreApplicationRequest extends FormRequest
{
    /** Hanya pengguna dengan email terverifikasi yang boleh mengajukan toko. */
    public function authorize(): bool
    {
        return $this->user()?->hasVerifiedEmail() ?? false;
    }

    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            ApiResponse::error('Verifikasi email Anda terlebih dahulu sebelum mengajukan toko.', 403)
        );
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        $phone = $this->input('phone');
        if (is_string($phone) && trim($phone) !== '') {
            $merge['phone'] = PhoneNumber::normalize($phone) ?? trim($phone);
        }

        $nik = $this->input('id_number');
        if (is_string($nik)) {
            $merge['id_number'] = preg_replace('/[\s.\-]/', '', $nik);
        }

        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $foto = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'];

        return [
            'store_name'     => ['required', 'string', 'min:3', 'max:100'],
            'description'    => ['nullable', 'string', 'max:2000'],
            'phone'          => ['required', 'string', 'regex:/^628\d{7,11}$/'],
            'province'       => ['required', 'string', 'max:100'],
            'city'           => ['required', 'string', 'max:100'],
            'district'       => ['required', 'string', 'max:100'],
            'village'        => ['nullable', 'string', 'max:100'],
            'postal_code'    => ['required', 'string', 'regex:/^\d{5}$/'],
            'street'         => ['required', 'string', 'min:5', 'max:500'],
            'id_holder_name' => ['required', 'string', 'min:2', 'max:100'],
            'id_number'      => ['required', 'string', 'regex:/^\d{16}$/'],
            'id_photo'       => array_merge(['required'], $foto),
            'selfie'         => array_merge(['nullable'], $foto),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'store_name.required'     => 'Nama toko wajib diisi.',
            'store_name.min'          => 'Nama toko minimal 3 karakter.',
            'phone.required'          => 'Nomor telepon toko wajib diisi.',
            'phone.regex'             => 'Nomor telepon tidak valid. Gunakan nomor seluler Indonesia, contoh 081234567890.',
            'province.required'       => 'Provinsi wajib diisi.',
            'city.required'           => 'Kota/kabupaten wajib diisi.',
            'district.required'       => 'Kecamatan wajib diisi.',
            'postal_code.required'    => 'Kode pos wajib diisi.',
            'postal_code.regex'       => 'Kode pos harus 5 digit angka.',
            'street.required'         => 'Alamat lengkap wajib diisi.',
            'street.min'              => 'Alamat lengkap minimal 5 karakter.',
            'id_holder_name.required' => 'Nama sesuai identitas wajib diisi.',
            'id_number.required'      => 'NIK wajib diisi.',
            'id_number.regex'         => 'NIK harus 16 digit angka.',
            'id_photo.required'       => 'Foto identitas wajib diunggah.',
            'id_photo.image'          => 'Foto identitas harus berupa gambar.',
            'id_photo.mimes'          => 'Format foto identitas harus JPG, PNG, atau WEBP.',
            'id_photo.max'            => 'Ukuran foto identitas maksimal 5 MB.',
            'id_photo.dimensions'     => 'Dimensi foto identitas maksimal 4096 x 4096 piksel.',
            'selfie.image'            => 'Selfie harus berupa gambar.',
            'selfie.mimes'            => 'Format selfie harus JPG, PNG, atau WEBP.',
            'selfie.max'              => 'Ukuran selfie maksimal 5 MB.',
            'selfie.dimensions'       => 'Dimensi selfie maksimal 4096 x 4096 piksel.',
        ];
    }
}
