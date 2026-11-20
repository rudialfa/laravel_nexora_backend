<?php

namespace App\Http\Requests\Cart;

use App\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;

class SelectCartItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'variant_ids'   => ['required', 'array', 'min:1', 'max:' . CartService::MAX_LINES],
            'variant_ids.*' => ['uuid', 'distinct'],
            'selected'      => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'variant_ids.required'   => 'Daftar varian wajib diisi.',
            'variant_ids.min'        => 'Pilih minimal 1 varian.',
            'variant_ids.max'        => 'Maksimal ' . CartService::MAX_LINES . ' varian per permintaan.',
            'variant_ids.*.uuid'     => 'Varian tidak valid.',
            'variant_ids.*.distinct' => 'Varian tidak boleh kembar.',
            'selected.required'      => 'selected wajib diisi.',
            'selected.boolean'       => 'selected harus true atau false.',
        ];
    }
}
