<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AddressService
{
    public const MAX_PER_USER = 10;

    /**
     * Menambah alamat. Alamat pertama otomatis menjadi alamat utama.
     * Baris pengguna dikunci agar dua permintaan bersamaan tidak bisa melewati batas
     * jumlah alamat atau menghasilkan dua alamat utama.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DomainException jika batas jumlah alamat tercapai
     */
    public function create(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data) {
            $this->lockOwner($user);

            if ($user->addresses()->count() >= self::MAX_PER_USER) {
                throw new DomainException(
                    'Maksimal ' . self::MAX_PER_USER . ' alamat per akun. Hapus salah satu alamat lama terlebih dahulu.'
                );
            }

            $jadikanUtama = (bool) ($data['is_default'] ?? false) || $user->addresses()->doesntExist();

            // user_id diisi otomatis oleh relasi, tidak pernah dari input.
            $alamat = $user->addresses()->create(Arr::except($data, ['is_default']) + ['is_default' => false]);

            if ($jadikanUtama) {
                $this->setDefault($user, $alamat);
            }

            return $alamat->fresh();
        });
    }

    /**
     * Mengubah alamat. is_default=true menjadikannya utama; is_default=false diabaikan
     * (alamat utama hanya berpindah saat alamat lain dijadikan utama).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, Address $alamat, array $data): Address
    {
        return DB::transaction(function () use ($user, $alamat, $data) {
            $this->lockOwner($user);

            $alamat->fill(Arr::except($data, ['is_default']))->save();

            if ($data['is_default'] ?? false) {
                $this->setDefault($user, $alamat);
            }

            return $alamat->fresh();
        });
    }

    /** Menjadikan alamat ini satu-satunya alamat utama milik pengguna. */
    public function setDefault(User $user, Address $alamat): Address
    {
        DB::transaction(function () use ($user, $alamat) {
            $this->lockOwner($user);

            $user->addresses()
                ->where('is_default', true)
                ->where('id', '!=', $alamat->id)
                ->update(['is_default' => false]);

            if (! $alamat->is_default) {
                $alamat->forceFill(['is_default' => true])->save();
            }
        });

        return $alamat->fresh();
    }

    /** Menghapus alamat (hapus lunak). Jika yang dihapus alamat utama, alamat terbaru yang tersisa dinaikkan. */
    public function delete(User $user, Address $alamat): void
    {
        DB::transaction(function () use ($user, $alamat) {
            $this->lockOwner($user);

            $adalahUtama = (bool) $alamat->is_default;

            $alamat->forceFill(['is_default' => false])->save();
            $alamat->delete();

            if ($adalahUtama) {
                $user->addresses()->orderByDesc('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });
    }

    private function lockOwner(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();
    }
}
