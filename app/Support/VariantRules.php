<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Aturan varian produk yang dipakai bersama oleh pembuatan produk, penambahan varian,
 * dan pengubahan varian.
 */
final class VariantRules
{
    public const MIN_PRICE    = 100;
    public const MAX_PRICE    = 1000000000;
    public const MAX_STOCK    = 1000000;
    public const MAX_OPTIONS  = 5;
    public const MAX_VARIANTS = 30;

    /**
     * Aturan per kolom (bentuk dan batas nilai saja).
     * Pemeriksaan antar kolom ada di assertConsistent().
     *
     * @param  string  $p  awalan kunci, contoh "variants.*." atau "" (kosong)
     * @param  bool  $partial  true saat mengubah (semua kolom boleh tidak dikirim)
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $p, bool $partial): array
    {
        $wajib = $partial ? ['sometimes', 'required'] : ['required'];

        $aturan = [
            $p . 'name'               => $partial ? ['sometimes', 'required', 'string', 'max:100'] : ['nullable', 'string', 'max:100'],
            $p . 'sku'                => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/'],
            $p . 'options'            => ['nullable', 'array', 'max:' . self::MAX_OPTIONS],
            $p . 'options.*'          => ['string', 'max:50'],
            $p . 'price'              => array_merge($wajib, ['integer', 'between:' . self::MIN_PRICE . ',' . self::MAX_PRICE]),
            $p . 'discount_price'     => ['nullable', 'integer', 'between:' . self::MIN_PRICE . ',' . self::MAX_PRICE],
            $p . 'discount_starts_at' => ['nullable', 'date'],
            $p . 'discount_ends_at'   => ['nullable', 'date'],
            $p . 'weight_gram'        => array_merge($wajib, ['integer', 'between:1,500000']),
            $p . 'length_cm'          => ['nullable', 'integer', 'between:1,1000'],
            $p . 'width_cm'           => ['nullable', 'integer', 'between:1,1000'],
            $p . 'height_cm'          => ['nullable', 'integer', 'between:1,1000'],
            $p . 'is_active'          => ['sometimes', 'boolean'],
        ];

        // Stok hanya boleh diisi saat varian dibuat. Setelahnya berubah lewat endpoint stok.
        if (! $partial) {
            $aturan[$p . 'stock'] = ['nullable', 'integer', 'between:0,' . self::MAX_STOCK];
        }

        return $aturan;
    }

    /** @return array<string, string> */
    public static function messages(string $p): array
    {
        return [
            $p . 'name.required'       => 'Nama varian wajib diisi.',
            $p . 'name.max'            => 'Nama varian maksimal 100 karakter.',
            $p . 'sku.max'             => 'SKU maksimal 64 karakter.',
            $p . 'sku.regex'           => 'SKU hanya boleh huruf, angka, titik, garis bawah, dan tanda hubung.',
            $p . 'options.array'       => 'Opsi varian harus berupa pasangan nama dan nilai.',
            $p . 'options.max'         => 'Opsi varian maksimal ' . self::MAX_OPTIONS . '.',
            $p . 'options.*.string'    => 'Nilai opsi varian harus berupa teks.',
            $p . 'options.*.max'       => 'Nilai opsi varian maksimal 50 karakter.',
            $p . 'price.required'      => 'Harga wajib diisi.',
            $p . 'price.integer'       => 'Harga harus berupa bilangan bulat rupiah.',
            $p . 'price.between'       => 'Harga harus di antara Rp 100 dan Rp 1.000.000.000.',
            $p . 'discount_price.integer' => 'Harga diskon harus berupa bilangan bulat rupiah.',
            $p . 'discount_price.between' => 'Harga diskon harus di antara Rp 100 dan Rp 1.000.000.000.',
            $p . 'discount_starts_at.date' => 'Awal masa diskon tidak valid.',
            $p . 'discount_ends_at.date'   => 'Akhir masa diskon tidak valid.',
            $p . 'weight_gram.required' => 'Berat wajib diisi.',
            $p . 'weight_gram.integer'  => 'Berat harus berupa bilangan bulat (gram).',
            $p . 'weight_gram.between'  => 'Berat harus di antara 1 dan 500.000 gram.',
            $p . 'length_cm.between'    => 'Panjang harus di antara 1 dan 1000 cm.',
            $p . 'width_cm.between'     => 'Lebar harus di antara 1 dan 1000 cm.',
            $p . 'height_cm.between'    => 'Tinggi harus di antara 1 dan 1000 cm.',
            $p . 'stock.integer'        => 'Stok harus berupa bilangan bulat.',
            $p . 'stock.between'        => 'Stok harus di antara 0 dan 1.000.000.',
            $p . 'is_active.boolean'    => 'is_active harus true atau false.',
        ];
    }

    /**
     * Memeriksa nilai AKHIR sebuah varian (setelah digabung dengan data lama saat mengubah).
     *
     * @param  array<string, mixed>  $v
     * @param  string  $p  awalan kunci error, contoh "variants.0." atau "" (kosong)
     *
     * @throws ValidationException
     */
    public static function assertConsistent(array $v, string $p = ''): void
    {
        $error = [];

        $harga   = $v['price'] ?? null;
        $diskon  = $v['discount_price'] ?? null;
        $mulai   = $v['discount_starts_at'] ?? null;
        $selesai = $v['discount_ends_at'] ?? null;

        if ($diskon !== null && $harga !== null && (int) $diskon >= (int) $harga) {
            $error[$p . 'discount_price'][] = 'Harga diskon harus lebih murah dari harga normal.';
        }

        if ($diskon === null && ($mulai !== null || $selesai !== null)) {
            $error[$p . 'discount_starts_at'][] = 'Masa diskon hanya boleh diisi bersama harga diskon.';
        }

        if ($mulai !== null && $selesai !== null && Carbon::parse($selesai)->lte(Carbon::parse($mulai))) {
            $error[$p . 'discount_ends_at'][] = 'Akhir masa diskon harus setelah awal masa diskon.';
        }

        $dimensiTerisi = count(array_filter(
            [$v['length_cm'] ?? null, $v['width_cm'] ?? null, $v['height_cm'] ?? null],
            fn($d) => $d !== null
        ));

        if ($dimensiTerisi !== 0 && $dimensiTerisi !== 3) {
            $error[$p . 'length_cm'][] = 'Panjang, lebar, dan tinggi harus diisi bersamaan atau dikosongkan semuanya.';
        }

        foreach ((array) ($v['options'] ?? []) as $kunci => $nilai) {
            if (! is_string($kunci) || trim($kunci) === '' || mb_strlen($kunci) > 30) {
                $error[$p . 'options'][] = 'Opsi varian harus berupa pasangan nama dan nilai (nama maksimal 30 karakter), contoh {"Warna":"Merah"}.';

                break;
            }
        }

        if ($error !== []) {
            throw ValidationException::withMessages($error);
        }
    }
}
