<?php

namespace App\Http\Requests\Seller;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOperatingHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hours'               => ['required', 'array', 'min:1', 'max:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:1,7', 'distinct'],
            'hours.*.is_closed'   => ['required', 'boolean'],
            'hours.*.open_time'   => ['nullable', 'date_format:H:i'],
            'hours.*.close_time'  => ['nullable', 'date_format:H:i'],
        ];
    }

    /** Hari buka wajib punya jam buka dan jam tutup, dan jam tutup harus setelah jam buka. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hours = $this->input('hours');

            if (! is_array($hours)) {
                return;
            }

            foreach ($hours as $i => $hari) {
                if (! is_array($hari) || filter_var($hari['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                // Format jam yang salah sudah dilaporkan aturan di atas.
                if ($validator->errors()->has("hours.{$i}.open_time") || $validator->errors()->has("hours.{$i}.close_time")) {
                    continue;
                }

                $buka  = $hari['open_time'] ?? null;
                $tutup = $hari['close_time'] ?? null;

                if (! $buka) {
                    $validator->errors()->add("hours.{$i}.open_time", 'Jam buka wajib diisi untuk hari buka.');
                }

                if (! $tutup) {
                    $validator->errors()->add("hours.{$i}.close_time", 'Jam tutup wajib diisi untuk hari buka.');
                }

                if ($buka && $tutup && $buka >= $tutup) {
                    $validator->errors()->add("hours.{$i}.close_time", 'Jam tutup harus setelah jam buka.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'hours.required'               => 'Jadwal buka wajib diisi.',
            'hours.min'                    => 'Jadwal buka minimal 1 hari.',
            'hours.max'                    => 'Jadwal buka maksimal 7 hari.',
            'hours.*.day_of_week.required' => 'Hari wajib diisi.',
            'hours.*.day_of_week.between'  => 'Hari harus 1 (Senin) sampai 7 (Minggu).',
            'hours.*.day_of_week.distinct' => 'Setiap hari hanya boleh muncul sekali.',
            'hours.*.is_closed.required'   => 'is_closed wajib diisi.',
            'hours.*.open_time.date_format'  => 'Jam buka harus berformat HH:MM, contoh 08:00.',
            'hours.*.close_time.date_format' => 'Jam tutup harus berformat HH:MM, contoh 17:00.',
        ];
    }
}
