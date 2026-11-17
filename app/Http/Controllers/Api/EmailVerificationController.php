<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /** POST /api/v1/auth/email/verification-notification (wajib login) */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(null, 'Email sudah terverifikasi.');
        }

        if (! $this->auth->sendVerificationEmail($user)) {
            return ApiResponse::error('Email verifikasi gagal dikirim. Coba lagi nanti.', 503);
        }

        return ApiResponse::success(null, 'Email verifikasi telah dikirim.');
    }

    /**
     * GET /api/v1/auth/email/verify/{id}/{hash}
     *
     * Tanda tangan digital dan masa berlaku tautan sudah diperiksa middleware "signed".
     * Di sini kita pastikan tautan memang untuk email akun saat ini: bila email sudah
     * diganti sejak tautan dibuat, tautan lama otomatis tidak berlaku.
     * Boleh dibuka tanpa login (pengguna biasanya membukanya dari aplikasi email).
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::query()->where('uuid', $id)->first();

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return ApiResponse::error('Tautan verifikasi tidak valid.', 403);
        }

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(null, 'Email sudah terverifikasi.');
        }

        $user->markEmailAsVerified();

        $this->auth->record($user->id, 'auth.email_verified', $request);

        return ApiResponse::success(null, 'Email berhasil diverifikasi.');
    }
}
