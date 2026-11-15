<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Pembuat respons JSON standar Nexora.
 * Dipakai oleh semua controller agar format selalu seragam.
 */
class ApiResponse
{
    /**
     * Respons berhasil.
     *
     * @param  mixed  $data    Data utama (array, objek, API Resource, atau null)
     * @param  array  $meta    Info tambahan, misalnya paginasi
     */
    public static function success(
        mixed $data = null,
        string $message = 'Berhasil.',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        $body = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        if (! empty($meta)) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    /**
     * Respons gagal.
     *
     * @param  mixed  $errors  Detail error, misalnya daftar error validasi per kolom
     * @param  array  $extra   Info tambahan (dipakai untuk detail debug)
     */
    public static function error(
        string $message,
        int $status = 400,
        mixed $errors = null,
        array $extra = []
    ): JsonResponse {
        $body = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json(array_merge($body, $extra), $status);
    }
}
