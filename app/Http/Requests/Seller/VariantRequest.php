<?php

namespace App\Http\Requests\Seller;

use App\Support\VariantRules;
use Illuminate\Foundation\Http\FormRequest;

class VariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * POST (tambah varian): harga dan berat wajib, stok awal boleh diisi.
     * PATCH (ubah varian): semua kolom opsional dan stok diabaikan.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return VariantRules::rules('', ! $this->isMethod('POST'));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return VariantRules::messages('');
    }
}
