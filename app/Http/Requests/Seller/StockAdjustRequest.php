<?php

namespace App\Http\Requests\Seller;

use App\Support\VariantRules;
use Illuminate\Foundation\Http\FormRequest;

class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $batas = VariantRules::MAX_STOCK;

        return [
            'delta' => ['required', 'integer', 'not_in:0', "between:-{$batas},{$batas}"],
            'note'  => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'delta.required' => 'Jumlah perubahan stok wajib diisi.',
            'delta.integer'  => 'Jumlah perubahan stok harus berupa bilangan bulat.',
            'delta.not_in'   => 'Jumlah perubahan stok tidak boleh nol.',
            'delta.between'  => 'Jumlah perubahan stok maksimal 1.000.000 per permintaan.',
            'note.max'       => 'Catatan maksimal 255 karakter.',
        ];
    }
}
