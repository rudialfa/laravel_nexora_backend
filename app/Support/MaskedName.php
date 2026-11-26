<?php

namespace App\Support;

/** Menyamarkan nama pengguna untuk ulasan dan pertanyaan yang tampil ke publik. */
final class MaskedName
{
    /** "Budi Santoso" menjadi "B*** S***". Anonim menjadi "Pengguna Anonim". */
    public static function of(?string $nama, bool $anonim = false): string
    {
        if ($anonim) {
            return 'Pengguna Anonim';
        }

        $kata = preg_split('/\s+/u', trim((string) $nama), -1, PREG_SPLIT_NO_EMPTY);

        if ($kata === false || $kata === []) {
            return 'Pengguna';
        }

        return implode(' ', array_map(fn(string $k) => mb_substr($k, 0, 1) . '***', array_slice($kata, 0, 3)));
    }
}
