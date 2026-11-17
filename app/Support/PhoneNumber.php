<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Mengubah nomor seluler Indonesia ke format 628xxxxxxxxx.
     * Menerima: 0812..., +62 812..., 62812..., 812..., 0062 812..., dan +62 0812...
     * Mengembalikan null jika bukan nomor seluler Indonesia yang valid
     * (misalnya telepon rumah atau teks asal-asalan).
     */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '62')) {
            $nomor = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $nomor = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $nomor = '62' . $digits;
        } else {
            return null;
        }

        // Orang sering menulis "+62 0812..." (nol ikut tertulis).
        if (str_starts_with($nomor, '620')) {
            $nomor = '62' . substr($nomor, 3);
        }

        return preg_match('/^628\d{7,11}$/', $nomor) === 1 ? $nomor : null;
    }
}
