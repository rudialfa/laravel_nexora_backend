<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreFollower;
use App\Models\User;
use App\Support\MaskedName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Mengikuti toko: pembeli mengikuti dan berhenti, seller melihat pengikutnya. */
class FollowService
{
    public const PESAN_TOKO_SENDIRI = 'Anda tidak dapat mengikuti toko Anda sendiri.';

    /**
     * Mengikuti toko aktif. Sudah mengikuti dijawab sukses tanpa menggandakan.
     *
     * @return array{created: bool, following: bool, followers_count: int}
     *
     * @throws ValidationException
     */
    public function follow(User $user, string $slug): array
    {
        $toko = Store::query()->where('slug', $slug)->where('status', 'active')->firstOrFail();

        if ((int) $toko->owner_id === (int) $user->id) {
            $this->reject('store', self::PESAN_TOKO_SENDIRI);
        }

        try {
            $baru = DB::transaction(function () use ($user, $toko) {
                // Toko dikunci agar penghitungan ulang pengikut tidak saling menimpa.
                Store::query()->whereKey($toko->id)->lockForUpdate()->first();

                $f = StoreFollower::query()->firstOrCreate(['store_id' => $toko->id, 'user_id' => $user->id]);

                $this->segarkan((int) $toko->id);

                return $f->wasRecentlyCreated;
            });
        } catch (UniqueConstraintViolationException) {
            $baru = false;

            $this->segarkan((int) $toko->id);
        }

        return ['created' => $baru, 'following' => true, 'followers_count' => $this->jumlah((int) $toko->id)];
    }

    /**
     * Berhenti mengikuti. Selalu berhasil, termasuk bila belum mengikuti atau toko sudah ditangguhkan.
     *
     * @return array{following: bool, followers_count: int}
     */
    public function unfollow(User $user, string $slug): array
    {
        $toko = Store::withTrashed()->where('slug', $slug)->firstOrFail();

        DB::transaction(function () use ($user, $toko) {
            Store::withTrashed()->whereKey($toko->id)->lockForUpdate()->first();

            StoreFollower::query()->where('store_id', $toko->id)->where('user_id', $user->id)->delete();

            $this->segarkan((int) $toko->id);
        });

        return ['following' => false, 'followers_count' => $this->jumlah((int) $toko->id)];
    }

    /**
     * Toko aktif yang diikuti pengguna ini, terbaru lebih dulu.
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function following(User $user, array $filter): array
    {
        $hal = StoreFollower::query()
            ->where('user_id', $user->id)
            ->whereHas('store', fn($q) => $q->where('status', 'active'))
            ->with('store')
            ->orderByDesc('id')
            ->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()->map(fn(StoreFollower $f) => [
                'id'              => $f->store->uuid,
                'name'            => $f->store->name,
                'slug'            => $f->store->slug,
                'city'            => $f->store->city,
                'logo_url'        => $f->store->logo_path ? Storage::disk('public')->url($f->store->logo_path) : null,
                'is_open'         => (bool) $f->store->is_open,
                'rating_avg'      => (float) $f->store->rating_avg,
                'rating_count'    => (int) $f->store->rating_count,
                'followers_count' => (int) $f->store->followers_count,
                'followed_at'     => $this->iso($f->created_at),
            ])->values()->all(),
            'meta'  => $this->meta($hal),
        ];
    }

    /**
     * Pengikut toko ini untuk seller: nama tersamar tanpa data pribadi.
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>, summary: array<string, int>}
     */
    public function followersOf(Store $store, array $filter): array
    {
        $hal = StoreFollower::query()
            ->where('store_id', $store->id)
            ->with('user')
            ->orderByDesc('id')
            ->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items'   => $hal->getCollection()->map(fn(StoreFollower $f) => [
                'name'        => MaskedName::of($f->user?->name),
                'followed_at' => $this->iso($f->created_at),
            ])->values()->all(),
            'meta'    => $this->meta($hal),
            'summary' => ['followers_count' => $this->jumlah((int) $store->id)],
        ];
    }

    // ---------------------------------------------------------------- PRIVAT

    private function jumlah(int $tokoId): int
    {
        return StoreFollower::query()->where('store_id', $tokoId)->count();
    }

    /** Jumlah pengikut selalu dihitung ulang dari tabel pengikut, bukan ditambah atau dikurangi. */
    private function segarkan(int $tokoId): void
    {
        DB::table('stores')->where('id', $tokoId)->update(['followers_count' => $this->jumlah($tokoId)]);
    }

    /** @return array<string, int> */
    private function meta($hal): array
    {
        return [
            'current_page' => $hal->currentPage(),
            'per_page'     => $hal->perPage(),
            'total'        => $hal->total(),
            'last_page'    => $hal->lastPage(),
        ];
    }

    private function iso(mixed $nilai): ?string
    {
        return $nilai === null ? null : Carbon::parse($nilai)->toIso8601String();
    }

    /** @throws ValidationException */
    private function reject(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
