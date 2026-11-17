<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\ProfileController;
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

        // Lupa dan reset password (publik, dibatasi).
        Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:password');
        Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:password');

        // Verifikasi email. Tautan di email bertanda tangan digital (middleware "signed").
        Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->whereUuid('id')
            ->where('hash', '[a-f0-9]{40}')
            ->middleware('signed')
            ->name('verification.verify');

        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware(['auth:sanctum', 'active', 'throttle:verification']);

        // Logout boleh dilakukan siapa pun yang tokennya valid, termasuk akun yang sedang diblokir.
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    // ---- Route yang wajib login DAN akun aktif ----
    Route::middleware(['auth:sanctum', 'active'])->group(function () {

        // Profil
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [ProfileController::class, 'update']);
        Route::put('/me/password', [ProfileController::class, 'password'])
            ->middleware('throttle:5,1,password-change');
        Route::post('/me/avatar', [ProfileController::class, 'avatar']);
        Route::delete('/me/avatar', [ProfileController::class, 'destroyAvatar']);

        // Alamat pengiriman (hanya milik pengguna yang login)
        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);
        Route::get('/addresses/{address}', [AddressController::class, 'show'])->whereUuid('address');
        Route::patch('/addresses/{address}', [AddressController::class, 'update'])->whereUuid('address');
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->whereUuid('address');
        Route::post('/addresses/{address}/default', [AddressController::class, 'makeDefault'])->whereUuid('address');
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
