<?php

namespace App\Http\Middleware;

use App\Support\AccountStatus;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak pengguna yang statusnya bukan "active" (suspended/banned),
 * walaupun token mereka masih valid. Dipasang setelah auth:sanctum.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status !== AccountStatus::ACTIVE) {
            return ApiResponse::error('Akun Anda tidak aktif. Hubungi dukungan Nexora.', 403);
        }

        return $next($request);
    }
}
