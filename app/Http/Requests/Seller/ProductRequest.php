<?php

namespace App\Http\Requests\Seller;

use App\Support\VariantRules;
use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * POST (tambah): nama, kategori, dan varian wajib. PATCH (ubah): semua kolom opsional,
     * varian tidak diterima di sini (diubah lewat endpoint varian).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $post  = $this->isMethod('POST');
        $wajib = $post ? 'required' : 'sometimes';

        $aturan = [
            'name'        => [$wajib, 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => [$wajib, 'required', 'uuid'],
            'brand_id'    => ['nullable', 'uuid'],
        ];

        if ($post) {
            $aturan['variants']   = ['required', 'array', 'min:1', 'max:' . VariantRules::MAX_VARIANTS];
            $aturan['variants.*'] = ['array'];
            $aturan += VariantRules::rules('variants.*.', false);
        }

        return $aturan;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $dasar = [
            'name.required'        => 'Nama produk wajib diisi.',
            'name.min'             => 'Nama produk minimal 3 karakter.',
            'name.max'             => 'Nama produk maksimal 150 karakter.',
            'description.max'      => 'Deskripsi maksimal 5000 karakter.',
            'category_id.required' => 'Kategori wajib dipilih.',
            'category_id.uuid'     => 'Kategori tidak valid.',
            'brand_id.uuid'        => 'Brand tidak valid.',
            'variants.required'    => 'Produk harus memiliki minimal 1 varian.',
            'variants.min'         => 'Produk harus memiliki minimal 1 varian.',
            'variants.max'         => 'Maksimal ' . VariantRules::MAX_VARIANTS . ' varian per produk.',
        ];

        return $this->isMethod('POST') ? $dasar + VariantRules::messages('variants.*.') : $dasar;
    }
}
