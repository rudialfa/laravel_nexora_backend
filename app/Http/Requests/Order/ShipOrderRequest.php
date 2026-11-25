<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShipOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delivery_type' => ['sometimes', Rule::in(['now', 'scheduled'])],
            'delivery_date' => ['required_if:delivery_type,scheduled', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:' . now()->addDays(14)->toDateString()],
            'delivery_time' => ['required_if:delivery_type,scheduled', 'nullable', 'date_format:H:i'],
            'note'          => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'delivery_type.in'               => 'Jenis penjemputan harus now atau scheduled.',
            'delivery_date.required_if'      => 'Tanggal penjemputan wajib diisi untuk penjemputan terjadwal.',
            'delivery_date.date_format'      => 'Tanggal penjemputan harus berformat YYYY-MM-DD.',
            'delivery_date.after_or_equal'   => 'Tanggal penjemputan tidak boleh sebelum hari ini.',
            'delivery_date.before_or_equal'  => 'Tanggal penjemputan maksimal 14 hari ke depan.',
            'delivery_time.required_if'      => 'Jam penjemputan wajib diisi untuk penjemputan terjadwal.',
            'delivery_time.date_format'      => 'Jam penjemputan harus berformat HH:mm.',
            'note.max'                       => 'Catatan maksimal 200 karakter.',
        ];
    }
}
