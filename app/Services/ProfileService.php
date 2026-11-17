<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class ProfileService
{
    /** Sisi terpanjang foto profil setelah diproses (piksel). */
    public const AVATAR_MAX_SIDE = 800;

    public function __construct(private readonly AuthService $auth) {}

    /**
     * Mengubah profil. Hanya name, phone, birth_date, dan gender yang diproses;
     * email, status, dan role tidak pernah diubah dari sini.
     *
     * Jika nomor telepon berganti, status verifikasi telepon direset.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(User $user, array $data, Request $request): User
    {
        $user->fill(Arr::only($data, ['name', 'birth_date', 'gender']));

        if (array_key_exists('phone', $data) && $data['phone'] !== $user->phone) {
            $user->phone             = $data['phone'];
            $user->phone_verified_at = null;
        }

        $user->save();

        $this->auth->record($user->id, 'profile.updated', $request, ['fields' => array_keys($data)]);

        return $user->fresh();
    }

    /**
     * Mengganti password. Semua token di perangkat LAIN dicabut; token yang
     * sedang dipakai tetap berlaku.
     *
     * @throws ValidationException jika password lama salah
     */
    public function changePassword(User $user, string $current, string $new, Request $request): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini salah.'],
            ]);
        }

        DB::transaction(function () use ($user, $new, $request) {
            // Cast "hashed" pada model User meng-hash password otomatis.
            $user->forceFill(['password' => $new])->save();

            $tokens   = $user->tokens();
            $sekarang = $user->currentAccessToken();

            if ($sekarang instanceof PersonalAccessToken) {
                $tokens->where('id', '!=', $sekarang->id);
            }

            $tokens->delete();

            $this->auth->record($user->id, 'auth.password_changed', $request);
        });
    }

    /**
     * Mengganti foto profil. Gambar dibuka, diperkecil, dan disimpan ulang sebagai JPEG
     * sehingga data tersembunyi (EXIF/GPS) dan muatan berbahaya tidak ikut tersimpan.
     * Foto lama dihapus setelah yang baru tersimpan.
     */
    public function replaceAvatar(User $user, UploadedFile $file, Request $request): User
    {
        $bytes = $this->normalizeAvatar($file);
        $baru  = 'avatars/' . Str::uuid()->toString() . '.jpg';
        $lama  = $user->avatar_path;
        $disk  = Storage::disk('public');

        $disk->put($baru, $bytes);

        try {
            $user->forceFill(['avatar_path' => $baru])->save();
        } catch (Throwable $e) {
            $disk->delete($baru);

            throw $e;
        }

        if ($lama !== null) {
            $disk->delete($lama);
        }

        $this->auth->record($user->id, 'profile.avatar_changed', $request);

        return $user->fresh();
    }

    public function removeAvatar(User $user, Request $request): User
    {
        $lama = $user->avatar_path;

        $user->forceFill(['avatar_path' => null])->save();

        if ($lama !== null) {
            Storage::disk('public')->delete($lama);
        }

        $this->auth->record($user->id, 'profile.avatar_removed', $request);

        return $user->fresh();
    }

    /** Mengembalikan isi file JPEG hasil pemrosesan ulang. */
    private function normalizeAvatar(UploadedFile $file): string
    {
        $sumber = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($sumber === false) {
            throw ValidationException::withMessages([
                'avatar' => ['File gambar tidak dapat diproses.'],
            ]);
        }

        $lebar  = imagesx($sumber);
        $tinggi = imagesy($sumber);
        $skala  = min(1, self::AVATAR_MAX_SIDE / max($lebar, $tinggi));

        $lebarBaru  = max(1, (int) round($lebar * $skala));
        $tinggiBaru = max(1, (int) round($tinggi * $skala));

        // Latar putih agar gambar transparan (PNG) tidak menjadi hitam di JPEG.
        $kanvas = imagecreatetruecolor($lebarBaru, $tinggiBaru);
        imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
        imagecopyresampled($kanvas, $sumber, 0, 0, 0, 0, $lebarBaru, $tinggiBaru, $lebar, $tinggi);

        ob_start();
        imagejpeg($kanvas, null, 85);

        return (string) ob_get_clean();
    }
}
