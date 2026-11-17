<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class PasswordController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * POST /api/v1/auth/forgot-password
     *
     * Jawabannya SELALU sama, baik email terdaftar maupun tidak, dan kegagalan
     * pengiriman email pun tidak dibocorkan. Dengan begitu orang tidak bisa
     * memeriksa email siapa yang terdaftar di Nexora.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            Password::broker()->sendResetLink(['email' => $request->validated('email')]);
        } catch (Throwable $e) {
            report($e);
        }

        return ApiResponse::success(null, 'Jika email terdaftar, tautan reset password telah dikirim.');
    }

    /**
     * POST /api/v1/auth/reset-password
     *
     * Token salah, email salah, dan token kedaluwarsa dijawab dengan pesan yang sama.
     * Setelah berhasil, SEMUA token login di semua perangkat dicabut.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->safe()->only(['email', 'token', 'password', 'password_confirmation']),
            function (User $user, string $password) use ($request) {
                // Cast "hashed" pada model User meng-hash password otomatis.
                $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
                $user->save();

                $user->tokens()->delete();

                $this->auth->record($user->id, 'auth.password_reset', $request);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(
                'Token reset password tidak valid atau sudah kedaluwarsa.',
                422,
                ['token' => ['Token reset password tidak valid atau sudah kedaluwarsa.']]
            );
        }

        return ApiResponse::success(null, 'Password berhasil diubah. Silakan login dengan password baru.');
    }
}
