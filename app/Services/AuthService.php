<?php

namespace App\Services;

use App\Exceptions\AccountInactiveException;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\AccountStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public const TOKEN_TTL_DAYS      = 30;
    public const DEFAULT_DEVICE_NAME = 'mobile';

    /**
     * Membuat akun baru dengan role customer.
     * Hanya name, email, phone, dan password yang diambil; kolom lain
     * (status, role, dan sebagainya) tidak pernah berasal dari input pengguna.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, Request $request): User
    {
        return DB::transaction(function () use ($data, $request) {
            $user = User::create(Arr::only($data, ['name', 'email', 'phone', 'password']));
            $user->assignRole('customer');

            $this->log($user->id, 'auth.register', $request);

            return $user;
        });
    }

    /**
     * Memeriksa email dan password.
     *
     * - Mengembalikan null jika email tidak dikenal atau password salah
     *   (pemanggil memberi pesan yang sama untuk keduanya).
     * - Melempar AccountInactiveException hanya jika password BENAR tetapi akun
     *   tidak aktif, sehingga status akun orang lain tidak bisa ditebak.
     *
     * @throws AccountInactiveException
     */
    public function login(string $email, string $password, Request $request): ?User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Menghabiskan waktu yang sebanding dengan pemeriksaan password asli,
            // agar lamanya respons tidak membocorkan apakah email terdaftar.
            Hash::make($password);

            return null;
        }

        if (! Hash::check($password, $user->password)) {
            $this->log($user->id, 'auth.login_failed', $request);

            return null;
        }

        if ($user->status !== AccountStatus::ACTIVE) {
            $this->log($user->id, 'auth.login_blocked', $request, ['status' => $user->status]);

            throw new AccountInactiveException;
        }

        if (Hash::needsRehash($user->password)) {
            $user->password = $password;   // cast "hashed" meng-hash ulang otomatis
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $this->log($user->id, 'auth.login', $request);

        return $user;
    }

    /** Menerbitkan token API yang kedaluwarsa dalam TOKEN_TTL_DAYS hari. */
    public function issueToken(User $user, ?string $deviceName = null): NewAccessToken
    {
        return $user->createToken(
            $deviceName ?: self::DEFAULT_DEVICE_NAME,
            ['*'],
            now()->addDays(self::TOKEN_TTL_DAYS)
        );
    }

    /** Mencabut token yang sedang dipakai (perangkat lain tidak terpengaruh). */
    public function logout(User $user, Request $request): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $this->log($user->id, 'auth.logout', $request);
    }

    /** @param  array<string, mixed>|null  $properties */
    private function log(?int $userId, string $action, Request $request, ?array $properties = null): void
    {
        ActivityLog::create([
            'user_id'    => $userId,
            'action'     => $action,
            'properties' => $properties,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);
    }
}
