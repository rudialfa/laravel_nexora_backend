<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Support\ImageNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StoreManagementService
{
    /** Kolom yang boleh diubah seller. Selain ini (status, komisi, rating, slug, pemilik) tidak pernah. */
    private const EDITABLE = [
        'name',
        'description',
        'phone',
        'province',
        'city',
        'district',
        'village',
        'postal_code',
        'street',
        'latitude',
        'longitude',
        'biteship_area_id',
        'timezone',
        'is_open',
        'closed_note',
    ];

    /** Jika salah satu berubah, kode area Biteship lama dianggap tidak berlaku lagi. */
    private const ADDRESS_FIELDS = [
        'province',
        'city',
        'district',
        'village',
        'postal_code',
        'street',
        'latitude',
        'longitude',
    ];

    public function __construct(private readonly AuthService $auth) {}

    /**
     * Mengubah profil toko. Slug tidak ikut berubah saat nama diganti.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Store $store, User $user, array $data, Request $request): Store
    {
        $data = Arr::only($data, self::EDITABLE);

        // Alamat berubah tetapi kode area baru tidak dikirim: kosongkan kode lama.
        if ($this->addressChanged($store, $data) && ! array_key_exists('biteship_area_id', $data)) {
            $data['biteship_area_id'] = null;
        }

        // Toko dibuka kembali: pesan "sedang libur" dihapus (kecuali seller mengirim pesan baru).
        if (($data['is_open'] ?? null) === true && ! array_key_exists('closed_note', $data)) {
            $data['closed_note'] = null;
        }

        $store->fill($data)->save();

        $this->auth->record($user->id, 'store.updated', $request, ['fields' => array_keys($data)]);

        return $this->reload($store);
    }

    /** Mengganti logo atau banner. Gambar diproses ulang menjadi JPEG; file lama dihapus setelah yang baru tersimpan. */
    public function setImage(Store $store, User $user, string $type, UploadedFile $file, Request $request): Store
    {
        [$kolom, $folder, $sisiMaks] = $this->imageSpec($type);

        $bytes = ImageNormalizer::jpeg($file, 'image', $sisiMaks);
        $baru  = $folder . '/' . Str::uuid()->toString() . '.jpg';
        $lama  = $store->{$kolom};
        $disk  = Storage::disk('public');

        $disk->put($baru, $bytes);

        try {
            $store->forceFill([$kolom => $baru])->save();
        } catch (Throwable $e) {
            $disk->delete($baru);

            throw $e;
        }

        if ($lama !== null) {
            $disk->delete($lama);
        }

        $this->auth->record($user->id, 'store.image_changed', $request, ['type' => $type]);

        return $this->reload($store);
    }

    public function removeImage(Store $store, User $user, string $type, Request $request): Store
    {
        [$kolom] = $this->imageSpec($type);

        $lama = $store->{$kolom};

        $store->forceFill([$kolom => null])->save();

        if ($lama !== null) {
            Storage::disk('public')->delete($lama);
        }

        $this->auth->record($user->id, 'store.image_removed', $request, ['type' => $type]);

        return $this->reload($store);
    }

    /**
     * Mengganti SELURUH jadwal buka. Hari yang tidak dikirim berarti tidak ada jadwal.
     * Hari libur (is_closed) tidak menyimpan jam.
     *
     * @param  array<int, array<string, mixed>>  $hours
     */
    public function replaceHours(Store $store, User $user, array $hours, Request $request): Store
    {
        DB::transaction(function () use ($store, $hours) {
            $store->operatingHours()->delete();

            foreach ($hours as $hari) {
                $libur = filter_var($hari['is_closed'], FILTER_VALIDATE_BOOLEAN);

                $store->operatingHours()->create([
                    'day_of_week' => (int) $hari['day_of_week'],
                    'is_closed'   => $libur,
                    'open_time'   => $libur ? null : $hari['open_time'],
                    'close_time'  => $libur ? null : $hari['close_time'],
                ]);
            }
        });

        $this->auth->record($user->id, 'store.hours_updated', $request, ['days' => count($hours)]);

        return $this->reload($store);
    }

    /**
     * Mengganti SELURUH daftar kurir toko. Daftar kosong berarti toko memakai
     * kurir bawaan marketplace (aturan ditetapkan di modul pengiriman).
     *
     * @param  array<int, array<string, mixed>>  $couriers
     */
    public function replaceCouriers(Store $store, User $user, array $couriers, Request $request): Store
    {
        DB::transaction(function () use ($store, $couriers) {
            $store->courierSettings()->delete();

            foreach ($couriers as $kurir) {
                $store->courierSettings()->create([
                    'courier_code' => $kurir['code'],
                    'is_active'    => filter_var($kurir['is_active'], FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        });

        $this->auth->record($user->id, 'store.couriers_updated', $request, ['count' => count($couriers)]);

        return $this->reload($store);
    }

    private function reload(Store $store): Store
    {
        return $store->fresh(['operatingHours', 'courierSettings']);
    }

    /** @return array{0: string, 1: string, 2: int} kolom, folder, sisi terpanjang (piksel) */
    private function imageSpec(string $type): array
    {
        return match ($type) {
            'logo'   => ['logo_path', 'stores/logos', 512],
            'banner' => ['banner_path', 'stores/banners', 1600],
        };
    }

    /** @param  array<string, mixed>  $data */
    private function addressChanged(Store $store, array $data): bool
    {
        foreach (self::ADDRESS_FIELDS as $kolom) {
            if (! array_key_exists($kolom, $data)) {
                continue;
            }

            $lama = $store->{$kolom};
            $baru = $data[$kolom];

            if (in_array($kolom, ['latitude', 'longitude'], true)) {
                $sama = ($lama === null && $baru === null)
                    || ($lama !== null && $baru !== null && round((float) $lama, 7) === round((float) $baru, 7));
            } else {
                $sama = trim((string) $lama) === trim((string) $baru);
            }

            if (! $sama) {
                return true;
            }
        }

        return false;
    }
}
