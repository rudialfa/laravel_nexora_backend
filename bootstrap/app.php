<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

    // Nama pendek untuk dipakai di routes/api.php: ->middleware('active')
    $middleware->alias([
        'active' => EnsureAccountIsActive::class,
    ]);

    // Nexora adalah API murni dan tidak punya halaman login.
    // Untuk /api/* jangan mengalihkan tamu ke route "login" (tidak ada),
    // cukup lempar error 401 yang diubah menjadi JSON di bawah.
    $middleware->redirectGuestsTo(
        fn(Request $request) => $request->is('api/*') ? null : '/'
        );
})
    ->withExceptions(function (Exceptions $exceptions): void {

        // Semua alamat /api/* selalu dijawab dengan JSON,
        // tidak peduli ada atau tidaknya header "Accept: application/json".
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // 422: data yang dikirim tidak lolos validasi.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'Data yang dikirim tidak valid.',
                    422,
                    $e->errors()
                );
            }
        });

        // 401: belum login atau token tidak valid.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'Anda belum login atau token tidak valid.',
                    401
                );
            }
        });

    // 403, 404, 405, 429, dan error HTTP lainnya.
    // Header bawaan exception (misalnya Retry-After pada 429) ikut diteruskan.
    $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();

                $message = match ($status) {
                    403     => 'Anda tidak memiliki akses untuk tindakan ini.',
                    404     => 'Data atau alamat yang diminta tidak ditemukan.',
                    405     => 'Metode HTTP tidak diizinkan untuk alamat ini.',
                    429     => 'Terlalu banyak permintaan. Coba lagi beberapa saat lagi.',
                    default => $e->getMessage() ?: 'Terjadi kesalahan.',
                };

            return ApiResponse::error($message, $status)->withHeaders($e->getHeaders());
            }
        });

        // 500: error tak terduga. Detail teknis hanya muncul saat APP_DEBUG=true,
        // agar di production tidak membocorkan informasi internal.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $extra = config('app.debug')
                    ? ['debug' => [
                        'exception' => get_class($e),
                        'message'   => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                    ]]
                    : [];

                return ApiResponse::error(
                    'Terjadi kesalahan pada server.',
                    500,
                    null,
                    $extra
                );
            }
        });
    })->create();
