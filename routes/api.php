<?php

use App\Http\Controllers\Api\AuthController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes Nexora
|--------------------------------------------------------------------------
| Semua route di file ini otomatis berawalan /api, lalu kita tambah /v1.
| Contoh alamat lengkap: http://127.0.0.1:8000/api/v1/ping
*/

Route::prefix('v1')->group(function () {

    // Route publik: untuk mengecek API hidup.
    Route::get('/ping', function () {
        return ApiResponse::success([
            'app'  => config('app.name'),
            'time' => now()->toIso8601String(),
        ], 'Nexora API berjalan');
    });

    // ---- Autentikasi ----
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

        // Logout boleh dilakukan siapa pun yang tokennya valid, termasuk akun yang sedang diblokir.
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    // ---- Route yang wajib login DAN akun aktif ----
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
    });

    // ---- Route khusus pengujian, tidak aktif di production ----
    if (! app()->environment('production')) {

        Route::post('/uji-validasi', function (Request $request) {
            $request->validate([
                'name' => ['required', 'string', 'min:3'],
            ]);

            return ApiResponse::success(null, 'Validasi lolos.');
        });

        Route::get('/uji-error', function () {
            throw new RuntimeException('Ini error uji coba.');
        });
    }
});
