<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Membuka gambar unggahan lalu menyimpannya ulang sebagai JPEG yang diperkecil.
 * Ini membuang metadata tersembunyi (misalnya koordinat GPS) dan menetralkan file
 * yang menyamar sebagai gambar.
 */
final class ImageNormalizer
{
    /**
     * @param  string  $field  nama kolom untuk pesan error jika gambar tidak bisa diproses
     * @return string isi file JPEG
     *
     * @throws ValidationException
     */
    public static function jpeg(UploadedFile $file, string $field, int $maxSide, int $quality = 85): string
    {
        $sumber = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($sumber === false) {
            throw ValidationException::withMessages([
                $field => ['File gambar tidak dapat diproses.'],
            ]);
        }

        $lebar  = imagesx($sumber);
        $tinggi = imagesy($sumber);
        $skala  = min(1, $maxSide / max($lebar, $tinggi));

        $lebarBaru  = max(1, (int) round($lebar * $skala));
        $tinggiBaru = max(1, (int) round($tinggi * $skala));

        // Latar putih agar gambar transparan (PNG) tidak menjadi hitam di JPEG.
        $kanvas = imagecreatetruecolor($lebarBaru, $tinggiBaru);
        imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
        imagecopyresampled($kanvas, $sumber, 0, 0, 0, 0, $lebarBaru, $tinggiBaru, $lebar, $tinggi);

        ob_start();
        imagejpeg($kanvas, null, $quality);

        return (string) ob_get_clean();
    }
}
