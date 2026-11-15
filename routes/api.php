<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes Nexora
|--------------------------------------------------------------------------
| Semua route di file ini otomatis berawalan /api.
| Kita tambahkan awalan versi /v1 agar ke depan mudah dikembangkan
| tanpa merusak aplikasi Flutter yang sudah dipakai.
|
| Contoh alamat lengkap: http://127.0.0.1:8000/api/v1/ping
*/

Route::prefix('v1')->group(function () {

    // Route publik: tidak butuh login. Untuk mengecek API hidup.
    Route::get('/ping', function () {
        return response()->json([
            'success' => true,
            'message' => 'Nexora API berjalan',
            'data'    => [
                'app'  => config('app.name'),
                'time' => now()->toIso8601String(),
            ],
        ]);
    });

    // Route terproteksi: wajib mengirim token Sanctum yang valid.
    // Ini hanya untuk pengujian sementara dan akan kita hapus nanti.
    Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
        return response()->json([
            'success' => true,
            'data'    => $request->user(),
        ]);
    });
});
