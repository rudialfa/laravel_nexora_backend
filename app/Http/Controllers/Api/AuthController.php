<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AccountInactiveException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /** POST /api/v1/auth/register */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user  = $this->auth->register($request->validated(), $request);
        $token = $this->auth->issueToken($user, $request->validated('device_name'));

        return ApiResponse::success($this->payload($user, $token), 'Registrasi berhasil.', 201);
    }

    /** POST /api/v1/auth/login */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = $this->auth->login(
                $request->validated('email'),
                $request->validated('password'),
                $request
            );
        } catch (AccountInactiveException) {
            return ApiResponse::error('Akun Anda tidak aktif. Hubungi dukungan Nexora.', 403);
        }

        // Pesan yang sama untuk email tidak dikenal dan password salah.
        if ($user === null) {
            return ApiResponse::error('Email atau password salah.', 401);
        }

        $token = $this->auth->issueToken($user, $request->validated('device_name'));

        return ApiResponse::success($this->payload($user, $token), 'Login berhasil.');
    }

    /** POST /api/v1/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user(), $request);

        return ApiResponse::success(null, 'Logout berhasil.');
    }

    /** GET /api/v1/me */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    /** @return array<string, mixed> */
    private function payload(User $user, NewAccessToken $token): array
    {
        return [
            'user'       => new UserResource($user),
            'token'      => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ];
    }
}
