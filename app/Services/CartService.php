<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CartService
{
    public const MAX_QUANTITY = 99;
    public const MAX_LINES    = 100;

    public const STATUS_AVAILABLE          = 'available';
    public const STATUS_OUT_OF_STOCK       = 'out_of_stock';
    public const STATUS_INSUFFICIENT_STOCK = 'insufficient_stock';
    public const STATUS_UNAVAILABLE        = 'unavailable';

    /** Satu pesan untuk semua alasan "tidak bisa dibeli" agar kondisi produk orang lain tidak bocor. */
    public const PESAN_TIDAK_TERSEDIA = 'Varian tidak ditemukan atau tidak tersedia.';
    public const PESAN_TOKO_SENDIRI   = 'Anda tidak dapat membeli produk dari toko Anda sendiri.';
    public const PESAN_STOK_HABIS     = 'Stok varian ini habis.';

    // -------------------------------------------------------------- TAMPILAN

    /**
     * Isi keranjang untuk dikirim ke aplikasi: dikelompokkan per toko, dengan status tiap item
     * dan ringkasan. Harga selalu dihitung dari varian saat ini (tidak pernah disimpan).
     *
     * @return array{groups: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function view(User $user): array
    {
        $ownStoreId = Store::query()->where('owner_id', $user->id)->value('id');

        $items = CartItem::query()
            ->where('user_id', $user->id)
            ->with(['variant.product' => $this->productRelations()])
            ->orderBy('id')
            ->get();

        $disk   = Storage::disk('public');
        $groups = [];

        $ringkasan = [
            'lines'             => 0,
            'quantity_total'    => 0,
            'selected_count'    => 0,
            'selected_quantity' => 0,
            'subtotal'          => 0,
            'total_weight_gram' => 0,
            'has_issues'        => false,
        ];

        foreach ($items as $item) {
            $variant = $item->variant;
            $product = $variant->product;
            $toko    = $product->store;

            [$status, $alasan] = $this->inspect($item, $ownStoreId);

            $jumlah     = (int) $item->quantity;
            $final      = $variant->effectivePrice();
            $lineTotal  = $final * $jumlah;
            $dihitung   = $status === self::STATUS_AVAILABLE && $item->is_selected;
            $gambar     = $product->images->first();

            $kunci = $product->store_id;

            if (! isset($groups[$kunci])) {
                $groups[$kunci] = [
                    'store'          => [
                        'id'      => $toko?->uuid,
                        'name'    => $toko?->name,
                        'slug'    => $toko?->slug,
                        'city'    => $toko?->city,
                        'is_open' => (bool) $toko?->is_open,
                    ],
                    'items'          => [],
                    'selected_count' => 0,
                    'subtotal'       => 0,
                ];
            }

            $groups[$kunci]['items'][] = [
                'variant_id'      => $variant->uuid,
                'product'         => [
                    'id'        => $product->uuid,
                    'name'      => $product->name,
                    'slug'      => $product->slug,
                    'image_url' => $gambar ? $disk->url($gambar->path) : null,
                ],
                'variant'         => [
                    'name'        => $variant->name,
                    'options'     => $variant->options,
                    'weight_gram' => (int) $variant->weight_gram,
                ],
                'price'           => (int) $variant->price,
                'final_price'     => $final,
                'discount_active' => $final < (int) $variant->price,
                'quantity'        => $jumlah,
                'stock'           => (int) $variant->stock,
                'line_total'      => $lineTotal,
                'is_selected'     => (bool) $item->is_selected,
                'status'          => $status,
                'reason'          => $alasan,
            ];

            $ringkasan['lines']++;
            $ringkasan['quantity_total'] += $jumlah;

            if ($status !== self::STATUS_AVAILABLE) {
                $ringkasan['has_issues'] = true;
            }

            if ($dihitung) {
                $ringkasan['selected_count']++;
                $ringkasan['selected_quantity'] += $jumlah;
                $ringkasan['subtotal']          += $lineTotal;
                $ringkasan['total_weight_gram'] += (int) $variant->weight_gram * $jumlah;

                $groups[$kunci]['selected_count']++;
                $groups[$kunci]['subtotal'] += $lineTotal;
            }
        }

        return ['groups' => array_values($groups), 'summary' => $ringkasan];
    }

    // ------------------------------------------------------------------ AKSI

    /**
     * Menambah item. Item yang sudah ada digabung (jumlahnya dijumlahkan).
     * Mengembalikan true jika baris baru dibuat, false jika jumlahnya yang bertambah.
     *
     * @throws ValidationException
     */
    public function add(User $user, string $variantUuid, int $quantity): bool
    {
        return DB::transaction(function () use ($user, $variantUuid, $quantity) {
            // Baris pengguna dikunci agar dua permintaan bersamaan tidak bisa melewati batas baris.
            $this->lockOwner($user);

            $variant = ProductVariant::query()
                ->where('uuid', $variantUuid)
                ->with(['product' => $this->productRelations()])
                ->first();

            if ($variant === null) {
                $this->reject('variant_id', self::PESAN_TIDAK_TERSEDIA);
            }

            $alasan = $this->unavailableReason($variant, $this->ownStoreId($user));

            if ($alasan === 'own_store') {
                $this->reject('variant_id', self::PESAN_TOKO_SENDIRI);
            }

            if ($alasan !== null) {
                $this->reject('variant_id', self::PESAN_TIDAK_TERSEDIA);
            }

            $stok = (int) $variant->stock;

            if ($stok <= 0) {
                $this->reject('variant_id', self::PESAN_STOK_HABIS);
            }

            $ada      = CartItem::query()->where('user_id', $user->id)->where('variant_id', $variant->id)->first();
            $sudahAda = (int) ($ada?->quantity ?? 0);

            $this->assertQuantity($sudahAda + $quantity, $stok, $sudahAda);

            if ($ada === null) {
                if (CartItem::query()->where('user_id', $user->id)->count() >= self::MAX_LINES) {
                    $this->reject('cart', 'Keranjang penuh (maksimal ' . self::MAX_LINES . ' jenis produk). Hapus beberapa item terlebih dahulu.');
                }

                CartItem::create([
                    'user_id'    => $user->id,
                    'variant_id' => $variant->id,
                    'quantity'   => $quantity,
                ]);

                return true;
            }

            $ada->update(['quantity' => $sudahAda + $quantity]);

            return false;
        });
    }

    /**
     * Mengubah jumlah dan/atau status pilih satu item. Jumlah diperiksa terhadap stok saat ini.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(User $user, string $variantUuid, array $data): void
    {
        DB::transaction(function () use ($user, $variantUuid, $data) {
            $this->lockOwner($user);

            $item = $this->findLine($user, $variantUuid);

            if (array_key_exists('quantity', $data)) {
                $item->load(['variant.product' => $this->productRelations()]);

                if ($this->unavailableReason($item->variant, $this->ownStoreId($user)) !== null) {
                    $this->reject('quantity', 'Produk ini tidak lagi tersedia untuk dibeli.');
                }

                $stok = (int) $item->variant->stock;

                if ($stok <= 0) {
                    $this->reject('quantity', self::PESAN_STOK_HABIS);
                }

                $this->assertQuantity((int) $data['quantity'], $stok);

                $item->quantity = (int) $data['quantity'];
            }

            if (array_key_exists('is_selected', $data)) {
                $item->is_selected = filter_var($data['is_selected'], FILTER_VALIDATE_BOOLEAN);
            }

            $item->save();
        });
    }

    /** Menghapus satu item. Tidak ditemukan atau milik orang lain: 404. */
    public function remove(User $user, string $variantUuid): void
    {
        $this->findLine($user, $variantUuid)->delete();
    }

    /** Mengosongkan seluruh keranjang pengguna ini. */
    public function clear(User $user): void
    {
        CartItem::query()->where('user_id', $user->id)->delete();
    }

    /**
     * Memilih atau membatalkan pilihan beberapa item sekaligus. Varian yang tidak ada di
     * keranjang pengguna ini diabaikan begitu saja (termasuk milik pengguna lain).
     *
     * @param  array<int, string>  $variantUuids
     */
    public function select(User $user, array $variantUuids, bool $selected): void
    {
        CartItem::query()
            ->where('user_id', $user->id)
            ->whereIn('variant_id', ProductVariant::query()->whereIn('uuid', $variantUuids)->select('id'))
            ->update(['is_selected' => $selected]);
    }

    // ---------------------------------------------------------------- PRIVAT

    /**
     * Kondisi item untuk ditampilkan. Pemeriksaan "tidak tersedia" lebih dulu, lalu stok.
     *
     * @return array{0: string, 1: string|null} status dan alasan
     */
    private function inspect(CartItem $item, ?int $ownStoreId): array
    {
        $variant = $item->variant;
        $alasan  = $this->unavailableReason($variant, $ownStoreId);

        if ($alasan !== null) {
            return [self::STATUS_UNAVAILABLE, $alasan];
        }

        $stok = (int) $variant->stock;

        if ($stok <= 0) {
            return [self::STATUS_OUT_OF_STOCK, null];
        }

        if ((int) $item->quantity > $stok) {
            return [self::STATUS_INSUFFICIENT_STOCK, null];
        }

        return [self::STATUS_AVAILABLE, null];
    }

    /** Alasan varian ini tidak boleh dibeli pengguna ini, atau null jika boleh. Relasi harus sudah dimuat. */
    private function unavailableReason(ProductVariant $variant, ?int $ownStoreId): ?string
    {
        $product  = $variant->product;
        $store    = $product->store;
        $category = $product->category;

        return match (true) {
            $product->trashed() || $product->status !== Product::STATUS_ACTIVE                 => 'product_unavailable',
            $store === null || $store->trashed() || $store->status !== 'active'                => 'store_unavailable',
            $category === null || $category->trashed() || ! $category->is_active               => 'category_unavailable',
            ! $variant->is_active                                                              => 'variant_inactive',
            $ownStoreId !== null && (int) $product->store_id === $ownStoreId                   => 'own_store',
            default                                                                            => null,
        };
    }

    /** @throws ValidationException */
    private function assertQuantity(int $total, int $stok, ?int $sudahAda = null): void
    {
        if ($total > self::MAX_QUANTITY) {
            $this->reject('quantity', 'Maksimal ' . self::MAX_QUANTITY . ' per produk.');
        }

        if ($total > $stok) {
            $pesan = $sudahAda === null
                ? "Stok tidak cukup. Stok tersedia: {$stok}."
                : "Stok tidak cukup. Stok tersedia: {$stok}, sudah ada di keranjang: {$sudahAda}.";

            $this->reject('quantity', $pesan);
        }
    }

    private function findLine(User $user, string $variantUuid): CartItem
    {
        return CartItem::query()
            ->where('user_id', $user->id)
            ->whereIn('variant_id', ProductVariant::query()->where('uuid', $variantUuid)->select('id'))
            ->firstOrFail();
    }

    /** Relasi produk yang dibutuhkan untuk menilai ketersediaan (termasuk yang sudah dihapus lunak). */
    private function productRelations(): Closure
    {
        return function ($query) {
            $query->withTrashed()->with([
                'store'    => fn($w) => $w->withTrashed(),
                'category' => fn($w) => $w->withTrashed(),
                'images',
            ]);
        };
    }

    private function ownStoreId(User $user): ?int
    {
        $id = Store::query()->where('owner_id', $user->id)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function lockOwner(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();
    }

    /** @throws ValidationException */
    private function reject(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
