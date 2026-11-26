<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\ReviewImage;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\User;
use App\Support\MaskedName;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ulasan produk dan toko: pembuatan, daftar publik, daftar seller, balasan, dan rating rata-rata.
 */
class ReviewService
{
    public const WINDOW_DAYS = 30;

    public const PESAN_BELUM_SELESAI  = 'Ulasan hanya dapat dibuat setelah pesanan selesai.';
    public const PESAN_LEWAT_BATAS    = 'Batas waktu ulasan (30 hari sejak pesanan selesai) sudah lewat.';
    public const PESAN_SUDAH_ADA      = 'Anda sudah memberi ulasan untuk produk ini.';
    public const PESAN_SUDAH_ADA_TOKO = 'Anda sudah memberi ulasan untuk toko pada pesanan ini.';
    public const PESAN_FOTO_GAGAL     = 'Foto ulasan tidak dapat diproses.';
    public const PESAN_SUDAH_DIBALAS  = 'Ulasan ini sudah dibalas.';

    // --------------------------------------------------------- PRODUK PUBLIK

    /** Produk yang tayang untuk publik. Selain itu: 404. */
    public function publicProduct(string $slug): Product
    {
        return Product::query()
            ->where('slug', $slug)
            ->where('status', Product::STATUS_ACTIVE)
            ->whereHas('store', fn($q) => $q->where('status', 'active'))
            ->whereHas('category', fn($q) => $q->where('is_active', true))
            ->with('store')
            ->firstOrFail();
    }

    public function publicStore(string $slug): Store
    {
        return Store::query()->where('slug', $slug)->where('status', 'active')->firstOrFail();
    }

    // ---------------------------------------------------------------- PEMBELI

    /**
     * Item dan toko dari pesanan selesai (30 hari terakhir) yang belum dinilai.
     *
     * @return array{products: array<int, array<string, mixed>>, stores: array<int, array<string, mixed>>}
     */
    public function pending(User $user): array
    {
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->where('completed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->with(['store', 'items'])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $items = $orders->flatMap(fn(Order $o) => $o->items);

        $sudahItem = ProductReview::query()->whereIn('order_item_id', $items->pluck('id'))->pluck('order_item_id')->all();
        $sudahToko = StoreReview::query()->whereIn('order_id', $orders->pluck('id'))->pluck('order_id')->all();
        $varian    = ProductVariant::query()->whereIn('id', $items->pluck('variant_id')->unique())->pluck('uuid', 'id');
        $slug      = Product::withTrashed()->whereIn('id', $items->pluck('product_id')->unique())->pluck('slug', 'id');
        $disk      = Storage::disk('public');

        $produk = [];
        $toko   = [];

        foreach ($orders as $o) {
            $sampai = Carbon::parse($o->completed_at)->addDays(self::WINDOW_DAYS);

            foreach ($o->items as $i) {
                if (in_array($i->id, $sudahItem, true) || ! isset($varian[$i->variant_id])) {
                    continue;
                }

                $produk[] = [
                    'order'        => ['id' => $o->uuid, 'number' => $o->number],
                    'variant_id'   => $varian[$i->variant_id],
                    'product'      => [
                        'name'      => $i->product_name,
                        'slug'      => $slug[$i->product_id] ?? null,
                        'image_url' => $i->image_path ? $disk->url($i->image_path) : null,
                    ],
                    'variant_name' => $i->variant_name,
                    'quantity'     => (int) $i->quantity,
                    'completed_at' => $this->iso($o->completed_at),
                    'review_until' => $this->iso($sampai),
                ];
            }

            if (! in_array($o->id, $sudahToko, true)) {
                $toko[] = [
                    'order'        => ['id' => $o->uuid, 'number' => $o->number],
                    'store'        => ['id' => $o->store?->uuid, 'name' => $o->store?->name],
                    'completed_at' => $this->iso($o->completed_at),
                    'review_until' => $this->iso($sampai),
                ];
            }
        }

        return ['products' => $produk, 'stores' => $toko];
    }

    /**
     * Membuat ulasan produk. Foto diproses ulang menjadi JPEG sebelum disimpan.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $foto
     *
     * @throws ValidationException
     */
    public function createProductReview(User $user, string $orderUuid, array $data, array $foto): ProductReview
    {
        $order = Order::query()->where('user_id', $user->id)->where('uuid', $orderUuid)->firstOrFail();

        $this->pastikanBisaDiulas($order);

        $item = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereIn('variant_id', ProductVariant::query()->where('uuid', $data['variant_id'])->select('id'))
            ->firstOrFail();

        if (ProductReview::query()->where('order_item_id', $item->id)->exists()) {
            $this->reject('order', self::PESAN_SUDAH_ADA);
        }

        // Semua foto diperiksa dan diproses lebih dulu: satu foto rusak membatalkan seluruh ulasan.
        $binari = array_map(fn(UploadedFile $f) => $this->prosesFoto($f), array_values($foto));

        $komentar = isset($data['comment']) ? trim((string) $data['comment']) : null;
        $komentar = $komentar === '' ? null : $komentar;
        $ditulis  = [];

        try {
            $review = DB::transaction(function () use ($user, $item, $data, $komentar, $binari, &$ditulis) {
                $r = new ProductReview([
                    'rating'       => (int) $data['rating'],
                    'comment'      => $komentar,
                    'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
                ]);
                $r->forceFill(['order_item_id' => $item->id, 'user_id' => $user->id, 'product_id' => $item->product_id]);
                $r->save();

                foreach ($binari as $i => $isi) {
                    $path = "reviews/{$r->uuid}/" . ($i + 1) . '.jpg';

                    Storage::disk('public')->put($path, $isi);
                    $ditulis[] = $path;

                    ReviewImage::create(['review_id' => $r->id, 'path' => $path, 'sort_order' => $i]);
                }

                $this->segarkanRatingProduk((int) $item->product_id);

                return $r;
            });
        } catch (UniqueConstraintViolationException) {
            $this->hapusBerkas($ditulis);

            $this->reject('order', self::PESAN_SUDAH_ADA);
        } catch (Throwable $e) {
            $this->hapusBerkas($ditulis);

            throw $e;
        }

        return $review->fresh();
    }

    /**
     * Membuat ulasan toko untuk satu pesanan.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createStoreReview(User $user, string $orderUuid, array $data): StoreReview
    {
        $order = Order::query()->where('user_id', $user->id)->where('uuid', $orderUuid)->firstOrFail();

        $this->pastikanBisaDiulas($order);

        if (StoreReview::query()->where('order_id', $order->id)->exists()) {
            $this->reject('order', self::PESAN_SUDAH_ADA_TOKO);
        }

        $komentar = isset($data['comment']) ? trim((string) $data['comment']) : null;
        $komentar = $komentar === '' ? null : $komentar;

        try {
            $review = DB::transaction(function () use ($user, $order, $data, $komentar) {
                $r = new StoreReview(['rating' => (int) $data['rating'], 'comment' => $komentar]);
                $r->forceFill(['order_id' => $order->id, 'user_id' => $user->id, 'store_id' => $order->store_id]);
                $r->save();

                $this->segarkanRatingToko((int) $order->store_id);

                return $r;
            });
        } catch (UniqueConstraintViolationException) {
            $this->reject('order', self::PESAN_SUDAH_ADA_TOKO);
        }

        return $review->fresh();
    }

    // ------------------------------------------------------------ DAFTAR

    /**
     * Ulasan produk yang tayang, dengan ringkasan bintang dari seluruh ulasan tayang produk itu.
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>, summary: array<string, mixed>}
     */
    public function listProductReviews(string $slug, array $filter): array
    {
        $produk = $this->publicProduct($slug);

        $dasar = ProductReview::query()->where('product_id', $produk->id)->where('status', ProductReview::STATUS_PUBLISHED);

        $q = (clone $dasar)->with(['user', 'orderItem', 'images']);

        if (filled($filter['rating'] ?? null)) {
            $q->where('rating', (int) $filter['rating']);
        }

        if (filter_var($filter['with_photos'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $q->whereHas('images');
        }

        $this->urutkan($q, $filter['sort'] ?? null);

        $hal = $q->paginate((int) ($filter['per_page'] ?? 10), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items'   => $hal->getCollection()->map(fn(ProductReview $r) => $this->presentReview($r))->values()->all(),
            'meta'    => $this->meta($hal),
            'summary' => $this->ringkasan($dasar),
        ];
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>, summary: array<string, mixed>}
     */
    public function listStoreReviews(string $slug, array $filter): array
    {
        $toko = $this->publicStore($slug);

        $dasar = StoreReview::query()->where('store_id', $toko->id)->where('status', 'published');

        $q = (clone $dasar)->with('user');

        if (filled($filter['rating'] ?? null)) {
            $q->where('rating', (int) $filter['rating']);
        }

        $this->urutkan($q, $filter['sort'] ?? null);

        $hal = $q->paginate((int) ($filter['per_page'] ?? 10), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items'   => $hal->getCollection()->map(fn(StoreReview $r) => [
                'id'         => $r->uuid,
                'rating'     => (int) $r->rating,
                'comment'    => $r->comment,
                'author'     => MaskedName::of($r->user?->name),
                'created_at' => $this->iso($r->created_at),
            ])->values()->all(),
            'meta'    => $this->meta($hal),
            'summary' => $this->ringkasan($dasar),
        ];
    }

    // ------------------------------------------------------------------ SELLER

    /**
     * Ulasan produk toko ini yang tayang. Filter: rating, replied (sudah dibalas atau belum).
     *
     * @param  array<string, mixed>  $filter
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listForSeller(Store $store, array $filter): array
    {
        $q = ProductReview::query()
            ->where('status', ProductReview::STATUS_PUBLISHED)
            ->whereHas('product', fn($p) => $p->withTrashed()->where('store_id', $store->id))
            ->with(['user', 'orderItem', 'images', 'product' => fn($p) => $p->withTrashed()]);

        if (filled($filter['rating'] ?? null)) {
            $q->where('rating', (int) $filter['rating']);
        }

        if (filled($filter['replied'] ?? null)) {
            filter_var($filter['replied'], FILTER_VALIDATE_BOOLEAN) ? $q->whereNotNull('seller_reply') : $q->whereNull('seller_reply');
        }

        $this->urutkan($q, $filter['sort'] ?? null);

        $hal = $q->paginate((int) ($filter['per_page'] ?? 15), ['*'], 'page', (int) ($filter['page'] ?? 1));

        return [
            'items' => $hal->getCollection()->map(fn(ProductReview $r) => $this->presentReview($r, true))->values()->all(),
            'meta'  => $this->meta($hal),
        ];
    }

    /**
     * Seller membalas ulasan produk tokonya satu kali.
     *
     * @throws ValidationException
     */
    public function reply(Store $store, string $uuid, string $teks): ProductReview
    {
        return DB::transaction(function () use ($store, $uuid, $teks) {
            $r = ProductReview::query()
                ->where('uuid', $uuid)
                ->where('status', ProductReview::STATUS_PUBLISHED)
                ->whereHas('product', fn($p) => $p->withTrashed()->where('store_id', $store->id))
                ->lockForUpdate()
                ->firstOrFail();

            if ($r->seller_reply !== null) {
                $this->reject('reply', self::PESAN_SUDAH_DIBALAS);
            }

            $r->forceFill(['seller_reply' => trim($teks), 'seller_replied_at' => now()])->save();

            return $r->fresh();
        });
    }

    // ------------------------------------------------------------------ TAMPILAN

    /** @return array<string, mixed> */
    public function presentReview(ProductReview $r, bool $denganProduk = false): array
    {
        $r->loadMissing(['user', 'orderItem', 'images']);

        $disk = Storage::disk('public');

        $data = [
            'id'           => $r->uuid,
            'rating'       => (int) $r->rating,
            'comment'      => $r->comment,
            'author'       => MaskedName::of($r->user?->name, (bool) $r->is_anonymous),
            'variant_name' => $r->orderItem?->variant_name,
            'images'       => $r->images->map(fn($i) => $disk->url($i->path))->values()->all(),
            'seller_reply' => $r->seller_reply === null ? null : ['text' => $r->seller_reply, 'replied_at' => $this->iso($r->seller_replied_at)],
            'created_at'   => $this->iso($r->created_at),
        ];

        if ($denganProduk) {
            $data['product'] = ['name' => $r->product?->name, 'slug' => $r->product?->slug];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function presentStoreReview(StoreReview $r): array
    {
        $r->loadMissing('user');

        return [
            'id'         => $r->uuid,
            'rating'     => (int) $r->rating,
            'comment'    => $r->comment,
            'author'     => MaskedName::of($r->user?->name),
            'created_at' => $this->iso($r->created_at),
        ];
    }

    // ---------------------------------------------------------------- PRIVAT

    /** @throws ValidationException */
    private function pastikanBisaDiulas(Order $o): void
    {
        if ($o->status !== Order::STATUS_COMPLETED || $o->completed_at === null) {
            $this->reject('order', self::PESAN_BELUM_SELESAI);
        }

        if (Carbon::parse($o->completed_at)->addDays(self::WINDOW_DAYS)->lt(now())) {
            $this->reject('order', self::PESAN_LEWAT_BATAS);
        }
    }

    /** Menghitung ulang rata-rata dan jumlah dari ulasan yang tayang. */
    private function segarkanRatingProduk(int $produkId): void
    {
        $a = ProductReview::query()
            ->where('product_id', $produkId)
            ->where('status', ProductReview::STATUS_PUBLISHED)
            ->selectRaw('count(*) as jumlah, avg(rating) as rata')
            ->first();

        DB::table('products')->where('id', $produkId)->update([
            'rating_count' => (int) $a->jumlah,
            'rating_avg'   => round((float) $a->rata, 2),
        ]);
    }

    private function segarkanRatingToko(int $tokoId): void
    {
        $a = StoreReview::query()
            ->where('store_id', $tokoId)
            ->where('status', 'published')
            ->selectRaw('count(*) as jumlah, avg(rating) as rata')
            ->first();

        DB::table('stores')->where('id', $tokoId)->update([
            'rating_count' => (int) $a->jumlah,
            'rating_avg'   => round((float) $a->rata, 2),
        ]);
    }

    /**
     * Foto diubah menjadi JPEG baru (maksimal 1600 px). Metadata dan muatan tersembunyi tidak ikut terbawa.
     *
     * @throws ValidationException
     */
    private function prosesFoto(UploadedFile $f): string
    {
        $isi    = @file_get_contents((string) $f->getRealPath());
        $gambar = $isi === false ? false : @imagecreatefromstring($isi);

        if ($gambar === false) {
            $this->reject('photos', self::PESAN_FOTO_GAGAL);
        }

        $w     = imagesx($gambar);
        $h     = imagesy($gambar);
        $skala = min(1, 1600 / max($w, $h));
        $lw    = max(1, (int) round($w * $skala));
        $lh    = max(1, (int) round($h * $skala));

        $kanvas = imagecreatetruecolor($lw, $lh);
        imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
        imagecopyresampled($kanvas, $gambar, 0, 0, 0, 0, $lw, $lh, $w, $h);

        ob_start();
        imagejpeg($kanvas, null, 85);

        return (string) ob_get_clean();
    }

    /** @param  array<int, string>  $path */
    private function hapusBerkas(array $path): void
    {
        if ($path !== []) {
            Storage::disk('public')->delete($path);
        }
    }

    private function urutkan(Builder $q, ?string $sort): void
    {
        match ($sort) {
            'highest' => $q->orderByDesc('rating')->orderByDesc('id'),
            'lowest'  => $q->orderBy('rating')->orderByDesc('id'),
            default   => $q->orderByDesc('id'),
        };
    }

    /** @return array{average: float|int, count: int, distribution: array<int, int>} */
    private function ringkasan(Builder $dasar): array
    {
        $baris = (clone $dasar)->selectRaw('rating, count(*) as jumlah')->groupBy('rating')->pluck('jumlah', 'rating');

        $sebaran = [];
        $total   = 0;
        $skor    = 0;

        foreach ([5, 4, 3, 2, 1] as $bintang) {
            $n = (int) ($baris[$bintang] ?? 0);

            $sebaran[$bintang] = $n;
            $total            += $n;
            $skor             += $n * $bintang;
        }

        return ['average' => $total > 0 ? round($skor / $total, 2) : 0, 'count' => $total, 'distribution' => $sebaran];
    }

    /** @return array<string, int> */
    private function meta(LengthAwarePaginator $hal): array
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
