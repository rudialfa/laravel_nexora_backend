<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreApplication;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ImageNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreApplicationService
{
    public const NIK_UNAVAILABLE_MESSAGE = 'NIK ini tidak dapat digunakan. Hubungi dukungan Nexora jika Anda merasa ini keliru.';

    /** Sisi terpanjang foto identitas setelah diproses (piksel). */
    public const PHOTO_MAX_SIDE = 1600;

    public function __construct(private readonly AuthService $auth) {}

    /**
     * Mengajukan pembukaan toko.
     *
     * Berkas dituliskan lebih dulu; jika ada pemeriksaan yang gagal (sudah punya toko,
     * pengajuan masih menunggu, NIK tidak bisa dipakai), seluruh berkas yang tadi
     * ditulis dihapus lagi dan tidak ada yang tersimpan.
     *
     * @param  array<string, mixed>  $data  data teks yang sudah divalidasi (tanpa berkas)
     *
     * @throws ValidationException
     */
    public function submit(
        User $user,
        array $data,
        UploadedFile $idPhoto,
        ?UploadedFile $selfie,
        Request $request
    ): StoreApplication {
        $hash = StoreApplication::hashIdNumber($data['id_number']);

        // Gambar diproses sebelum menyentuh database atau disk.
        $idBytes     = ImageNormalizer::jpeg($idPhoto, 'id_photo', self::PHOTO_MAX_SIDE);
        $selfieBytes = $selfie !== null ? ImageNormalizer::jpeg($selfie, 'selfie', self::PHOTO_MAX_SIDE) : null;

        $disk       = Storage::disk('local');
        $folder     = 'store-applications/' . Str::uuid();
        $idPath     = $folder . '/id.jpg';
        $selfiePath = $selfieBytes !== null ? $folder . '/selfie.jpg' : null;

        $disk->put($idPath, $idBytes);

        if ($selfieBytes !== null) {
            $disk->put($selfiePath, $selfieBytes);
        }

        try {
            $application = DB::transaction(function () use ($user, $data, $hash, $idPath, $selfiePath, $request) {
                // Baris pengguna dikunci agar dua permintaan bersamaan tidak sama-sama lolos.
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                if (Store::withTrashed()->where('owner_id', $user->id)->exists()) {
                    throw ValidationException::withMessages(['store' => ['Anda sudah memiliki toko.']]);
                }

                $masihMenunggu = StoreApplication::query()
                    ->where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->exists();

                if ($masihMenunggu) {
                    throw ValidationException::withMessages([
                        'store' => ['Anda masih memiliki pengajuan toko yang sedang diperiksa.'],
                    ]);
                }

                if (StoreApplication::query()->where('id_number_hash', $hash)->exists()) {
                    throw ValidationException::withMessages(['id_number' => [self::NIK_UNAVAILABLE_MESSAGE]]);
                }

                $application = new StoreApplication(
                    Arr::only($data, [
                        'store_name',
                        'description',
                        'phone',
                        'province',
                        'city',
                        'district',
                        'village',
                        'postal_code',
                        'street',
                        'id_holder_name',
                    ]) + ['id_photo_path' => $idPath, 'selfie_path' => $selfiePath]
                );
                $application->user_id = $user->id;
                $application->setIdNumber($data['id_number']);
                $application->save();

                $this->auth->record($user->id, 'store.application_submitted', $request, [
                    'application' => $application->uuid,
                ]);

                return $application;
            });
        } catch (UniqueConstraintViolationException) {
            // NIK yang sama masuk dari akun lain pada detik yang sama.
            $disk->deleteDirectory($folder);

            throw ValidationException::withMessages(['id_number' => [self::NIK_UNAVAILABLE_MESSAGE]]);
        } catch (Throwable $e) {
            $disk->deleteDirectory($folder);

            throw $e;
        }

        return $application->fresh(['store']);
    }

    /** Riwayat pengajuan milik pengguna ini, terbaru dulu. */
    public function mine(User $user)
    {
        return $user->storeApplications()->with('store')->orderByDesc('id')->get();
    }

    // ------------------------------------------------------------------ ADMIN

    public function paginate(?string $status, int $perPage): LengthAwarePaginator
    {
        return StoreApplication::query()
            ->with(['user', 'store'])
            ->when($status, fn($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid): StoreApplication
    {
        return StoreApplication::query()->with(['user', 'store'])->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Menampilkan NIK asli kepada admin. Setiap penampakan tercatat di audit log.
     *
     * @return array{id_number: string}
     */
    public function revealIdNumber(User $admin, StoreApplication $application, Request $request): array
    {
        $this->auth->record($admin->id, 'admin.store_application_viewed', $request, [
            'application' => $application->uuid,
        ]);

        return ['id_number' => $application->id_number_encrypted];
    }

    /** Jalur file foto di disk privat. Mengembalikan null jika tidak ada. Pembukaannya tercatat. */
    public function photoPath(User $admin, StoreApplication $application, string $type, Request $request): ?string
    {
        $path = $type === 'selfie' ? $application->selfie_path : $application->id_photo_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $this->auth->record($admin->id, 'admin.store_application_photo_viewed', $request, [
            'application' => $application->uuid,
            'photo'       => $type,
        ]);

        return $path;
    }

    /**
     * Menyetujui pengajuan: membuat toko (aktif dan terverifikasi), dompet toko,
     * dan memberi pemohon role seller (role customer tetap dimiliki).
     *
     * @throws ValidationException jika pengajuan sudah diproses atau pemohon sudah punya toko
     */
    public function approve(User $admin, StoreApplication $application, Request $request): StoreApplication
    {
        return DB::transaction(function () use ($admin, $application, $request) {
            $locked = StoreApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['application' => ['Pengajuan ini sudah diproses.']]);
            }

            $owner = User::query()->lockForUpdate()->findOrFail($locked->user_id);

            if (Store::withTrashed()->where('owner_id', $owner->id)->exists()) {
                throw ValidationException::withMessages(['application' => ['Pengguna ini sudah memiliki toko.']]);
            }

            $store = new Store([
                'name'        => $locked->store_name,
                'slug'        => $this->uniqueSlug($locked->store_name),
                'description' => $locked->description,
                'phone'       => $locked->phone,
                'province'    => $locked->province,
                'city'        => $locked->city,
                'district'    => $locked->district,
                'village'     => $locked->village,
                'postal_code' => $locked->postal_code,
                'street'      => $locked->street,
            ]);
            $store->owner_id    = $owner->id;
            $store->verified_at = now();
            $store->save();

            Wallet::create(['store_id' => $store->id]);

            $owner->assignRole('seller');

            $locked->forceFill([
                'status'           => 'approved',
                'store_id'         => $store->id,
                'reviewed_by'      => $admin->id,
                'reviewed_at'      => now(),
                'rejection_reason' => null,
            ])->save();

            $this->auth->record($admin->id, 'admin.store_application_approved', $request, [
                'application' => $locked->uuid,
                'store'       => $store->uuid,
            ]);

            return $locked->fresh(['user', 'store']);
        });
    }

    /**
     * Menolak pengajuan. Hash NIK dikosongkan sehingga NIK yang sama bisa dipakai lagi
     * (oleh pemohon yang sama saat mengajukan ulang, atau oleh pemilik NIK yang sebenarnya).
     * NIK terenkripsi tetap disimpan untuk audit.
     *
     * @throws ValidationException jika pengajuan sudah diproses
     */
    public function reject(User $admin, StoreApplication $application, string $reason, Request $request): StoreApplication
    {
        return DB::transaction(function () use ($admin, $application, $reason, $request) {
            $locked = StoreApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['application' => ['Pengajuan ini sudah diproses.']]);
            }

            $locked->forceFill([
                'status'           => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by'      => $admin->id,
                'reviewed_at'      => now(),
                'id_number_hash'   => null,
            ])->save();

            $this->auth->record($admin->id, 'admin.store_application_rejected', $request, [
                'application' => $locked->uuid,
            ]);

            return $locked->fresh(['user', 'store']);
        });
    }

    /** Slug unik dari nama toko; jika sudah dipakai (termasuk toko yang dihapus), ditambah akhiran acak. */
    private function uniqueSlug(string $name): string
    {
        $dasar = Str::limit(Str::slug($name) ?: 'toko', 100, '');
        $slug  = $dasar;

        while (Store::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $dasar . '-' . Str::lower(Str::random(5));
        }

        return $slug;
    }
}
