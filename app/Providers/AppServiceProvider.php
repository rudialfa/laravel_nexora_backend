<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
    }
}
