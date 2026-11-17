<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Registrasi: 10 per menit per alamat IP.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // Login: 5 per menit per kombinasi email dan IP (menghambat tebak password).
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by(
                (is_string($email) ? Str::lower($email) : '') . '|' . $request->ip()
            );
        });

        // Lupa dan reset password: 5 per menit per kombinasi email dan IP.
        RateLimiter::for('password', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by(
                (is_string($email) ? Str::lower($email) : '') . '|' . $request->ip()
            );
        });

        // Kirim ulang email verifikasi: 3 per menit per pengguna.
        RateLimiter::for('verification', function (Request $request) {
            return Limit::perMinute(3)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        // Tautan di email reset password mengarah ke aplikasi pengguna (Flutter/web), bukan ke API.
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return rtrim((string) config('nexora.frontend_url'), '/')
                . '/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });

        // Tautan verifikasi memakai UUID (bukan nomor urut) dan bertanda tangan digital.
        VerifyEmail::createUrlUsing(function (object $notifiable) {
            return URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id'   => $notifiable->uuid,
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });
    }
}
