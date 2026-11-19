<?php

namespace App\Http\Requests\Catalog;

use App\Services\ProductCatalogService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Mengubah in_stock=true/false menjadi 1/0 sebelum divalidasi. Nilai lain dibiarkan agar tetap ditolak. */
    protected function prepareForValidation(): void
    {
        $nilai = $this->input('in_stock');

        if (is_string($nilai)) {
            $peta  = ['true' => 1, 'false' => 0];
            $kunci = strtolower($nilai);

            if (array_key_exists($kunci, $peta)) {
                $this->merge(['in_stock' => $peta[$kunci]]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $slug  = ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        $harga = ['nullable', 'integer', 'between:0,1000000000'];

        return [
            'q'         => ['nullable', 'string', 'max:100'],
            'category'  => $slug,
            'brand'     => $slug,
            'store'     => $slug,
            'min_price' => $harga,
            'max_price' => $harga,
            'in_stock'  => ['nullable', 'boolean'],
            'sort'      => ['nullable', 'in:' . implode(',', ProductCatalogService::SORTS)],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** Harga maksimal tidak boleh lebih kecil dari harga minimal. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['min_price', 'max_price'])) {
                return;
            }

            $min = $this->input('min_price');
            $max = $this->input('max_price');

            if (is_numeric($min) && is_numeric($max) && (int) $max < (int) $min) {
                $validator->errors()->add('max_price', 'Harga maksimal tidak boleh lebih kecil dari harga minimal.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.max'               => 'Kata pencarian maksimal 100 karakter.',
            'category.regex'      => 'Slug kategori tidak valid.',
            'brand.regex'         => 'Slug brand tidak valid.',
            'store.regex'         => 'Slug toko tidak valid.',
            'min_price.integer'   => 'Harga minimal harus berupa bilangan bulat rupiah.',
            'min_price.between'   => 'Harga minimal harus di antara 0 dan 1.000.000.000.',
            'max_price.integer'   => 'Harga maksimal harus berupa bilangan bulat rupiah.',
            'max_price.between'   => 'Harga maksimal harus di antara 0 dan 1.000.000.000.',
            'in_stock.boolean'    => 'in_stock harus 1, 0, true, atau false.',
            'sort.in'             => 'Urutan harus newest, best_selling, price_asc, price_desc, atau rating.',
            'per_page.integer'    => 'per_page harus berupa angka.',
            'per_page.min'        => 'per_page minimal 1.',
            'per_page.max'        => 'per_page maksimal 50.',
        ];
    }
}
