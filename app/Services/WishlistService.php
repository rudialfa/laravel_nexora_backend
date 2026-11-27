<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Wishlist pembeli: tambah, hapus, cek banyak produk, dan daftar. */
class WishlistService
{
    public const PESAN_TIDAK_TERSEDIA = 'Produk tidak ditemukan atau tidak tersedia.';
    public const PESAN_TOKO_SENDIRI   = 'Anda tidak dapat menyimpan produk dari toko Anda sendiri.';

    /** Batas jumlah produk per akun. Bisa diubah lewat config('wishlist.max'). */
    private function batas(): int
    {
        return max(1, (int) config('wishlist.max', 500));
    }

    /**
     * Menambah produk. Produk yang sudah ada dijawab sukses tanpa menggandakan.
     *
     * @return array{created: bool, count: int}
     *
     * @throws ValidationException
     */
    public function add(User $user, string $productUuid): array
    {
        $produk = Product::query()
            ->where('uuid', strtolower($productUuid))
            ->where('status', Product::STATUS_ACTIVE)
            ->whereHas('store', fn($q) => $q->where('status', 'active'))
            ->whereHas('category', fn($q) => $q->where('is_active', true))
            ->with('store')
            ->first();

        // Satu pesan untuk produk yang tidak ada dan yang tidak tayang agar kondisi produk lain tidak bocor.
        if ($produk === null) {
            $this->reject('product_id', self::PESAN_TIDAK_TERSEDIA);
        }

        if ((int) $produk->store->owner_id === (int) $user->id) {
            $this->reject('product_id', self::PESAN_TOKO_SENDIRI);
        }

        try {
            $baru = DB::transaction(function () use ($user, $produk) {
                // Satu pengguna diproses satu per satu agar dua permintaan tidak melewati batas.
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                if (Wishlist::query()->where('user_id', $user->id)->where('product_id', $produk->id)->exists()) {
                    return false;
                }

                if (Wishlist::query()->where('user_id', $user->id)->count() >= $this->batas()) {
                    $this->reject('product_id', "Wishlist penuh (maksimal {$this->batas()} produk). Hapus beberapa produk terlebih dahulu.");
                }

                Wishlist::create(['user_id' => $user->id, 'product_id' => $produk->id]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $baru = false;
        }

        return ['created' => $baru, 'count' => $this->hitung($user)];
    }

    /** Menghapus produk dari wishlist. Selalu berhasil, termasuk untuk produk yang tidak ada di wishlist. */
    public function remove(User $user, string $productUuid): int
    {
        Wishlist::query()
            ->where('user_id', $user->id)
            ->whereIn('product_id', Product::withTrashed()->where('uuid', strtolower($productUuid))->select('id'))
            ->delete();

        return $this->hitung($user);
    }

    /**
     * Menandai produk mana saja yang ada di wishlist pengguna ini.
     *
     * @param  array<int, string>  $uuids
     * @return array<string, bool> UUID menjadi true atau false
     */
    public function check(User $user, array $uuids): array
    {
        $uuids = array_values(array_unique(array_map('strtolower', $uuids)));

        $ada = Product::withTrashed()
            ->whereIn('uuid', $uuids)
            ->whereIn('id', Wishlist::query()->where('user_id', $user->id)->select('product_id'))
            ->pluck('uuid')
            ->all();

        $hasil = [];

        foreach ($uuids as $uuid) {
            $hasil[$uuid] = in_array($uuid, $ada, true);
        }

        return $hasil;
    }

    /**
     * Daftar wishlist, terbaru lebih dulu. Produk yang tidak lagi tayang tetap tampil dengan alasannya.
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function list(User $user, array $filter): array
    {
        $q = Wishlist::query()
            ->where('user_id', $user->id)
            ->with(['product' => fn($p) => $p->withTrashed()->with([
                'store'    => fn($w) => $w->withTrashed(),
                'category' => fn($w) => $w->withTrashed(),
                'images',
            ])])
            ->orderByDesc('id');

        if (filled($filter['q'] ?? null)) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filter['q']))) . '%';

            $q->whereHas('product', fn($p) => $p->withTrashed()->whereRaw("lower(name) like ? escape '!'", [$like]));
        }

        $hal = $q->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()
                ->filter(fn(Wishlist $w) => $w->product !== null)
                ->map(fn(Wishlist $w) => $this->present($w))
                ->values()
                ->all(),
            'meta'  => [
                'current_page' => $hal->currentPage(),
                'per_page'     => $hal->perPage(),
                'total'        => $hal->total(),
                'last_page'    => $hal->lastPage(),
            ],
        ];
    }

    public function hitung(User $user): int
    {
        return Wishlist::query()->where('user_id', $user->id)->count();
    }

    /** @return array<string, mixed> */
    private function present(Wishlist $w): array
    {
        $p      = $w->product;
        $alasan = $this->alasan($p);
        $gambar = $p->images->sortBy('sort_order')->first();

        return [
            'id'           => $p->uuid,
            'name'         => $p->name,
            'slug'         => $p->slug,
            'price_min'    => (int) $p->price_min,
            'price_max'    => (int) $p->price_max,
            'image_url'    => $gambar ? Storage::disk('public')->url($gambar->path) : null,
            'rating_avg'   => (float) $p->rating_avg,
            'rating_count' => (int) $p->rating_count,
            'sold_count'   => (int) $p->sold_count,
            'store'        => ['id' => $p->store?->uuid, 'name' => $p->store?->name, 'slug' => $p->store?->slug, 'city' => $p->store?->city],
            'available'    => $alasan === null,
            'reason'       => $alasan,
            'added_at'     => $w->created_at === null ? null : Carbon::parse($w->created_at)->toIso8601String(),
        ];
    }

    /** Alasan produk tidak lagi tayang, atau null jika masih tayang. Relasi harus sudah dimuat. */
    private function alasan(Product $p): ?string
    {
        return match (true) {
            $p->trashed() || $p->status !== Product::STATUS_ACTIVE                         => 'product_unavailable',
            $p->store === null || $p->store->trashed() || $p->store->status !== 'active'   => 'store_unavailable',
            $p->category === null || $p->category->trashed() || ! $p->category->is_active => 'category_unavailable',
            default                                                                        => null,
        };
    }

    /** @throws ValidationException */
    private function reject(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
