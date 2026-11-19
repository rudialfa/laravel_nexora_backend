<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductCatalogService
{
    public const SORTS            = ['newest', 'best_selling', 'price_asc', 'price_desc', 'rating'];
    public const SECTION_LIMIT    = 10;
    public const RELATED_LIMIT    = 10;
    public const MAX_SEARCH_WORDS = 5;

    private const LIST_RELATIONS = ['category', 'store', 'images'];

    /**
     * Dasar semua penelusuran publik: produk tayang, tokonya aktif, kategorinya aktif.
     * Produk yang dihapus dikeluarkan otomatis oleh hapus lunak.
     */
    public function visible(): Builder
    {
        return Product::query()
            ->active()
            ->whereHas('store', fn($w) => $w->where('status', 'active'))
            ->whereHas('category', fn($w) => $w->where('is_active', true));
    }

    /**
     * Daftar produk dengan pencarian, filter, dan pengurutan.
     *
     * @param  array<string, mixed>  $f  parameter yang sudah divalidasi
     *
     * @throws ModelNotFoundException jika slug kategori, brand, atau toko tidak ada atau tidak aktif
     */
    public function search(array $f, int $perPage): LengthAwarePaginator
    {
        $query = $this->visible()->with(self::LIST_RELATIONS);

        // Setiap kata harus cocok di nama produk ATAU nama brand yang aktif.
        foreach ($this->words($f['q'] ?? null) as $kata) {
            $pola = '%' . $this->escapeLike($kata) . '%';

            $query->where(function (Builder $w) use ($pola) {
                $w->whereRaw("products.name LIKE ? ESCAPE '!'", [$pola])
                    ->orWhereHas('brand', fn($b) => $b
                        ->where('brands.is_active', true)
                        ->whereRaw("brands.name LIKE ? ESCAPE '!'", [$pola]));
            });
        }

        if (! empty($f['category'])) {
            $query->whereIn('category_id', $this->categoryIds($f['category']));
        }

        if (! empty($f['brand'])) {
            $brand = Brand::query()->where('slug', $f['brand'])->where('is_active', true)->firstOrFail();
            $query->where('brand_id', $brand->id);
        }

        if (! empty($f['store'])) {
            $toko = Store::query()->where('slug', $f['store'])->where('status', 'active')->firstOrFail();
            $query->where('store_id', $toko->id);
        }

        // Harga memakai harga terendah produk (angka "mulai dari").
        if (isset($f['min_price'])) {
            $query->where('price_min', '>=', (int) $f['min_price']);
        }

        if (isset($f['max_price'])) {
            $query->where('price_min', '<=', (int) $f['max_price']);
        }

        if (filter_var($f['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereHas('variants', fn($w) => $w->where('is_active', true)->where('stock', '>', 0));
        }

        $this->applySort($query, $f['sort'] ?? 'newest');

        return $query->paginate($perPage);
    }

    /**
     * Isi beranda: banner yang sedang tayang, kategori utama, serta tiga daftar produk.
     *
     * @return array{banners: Collection, categories: Collection, featured: Collection, new_arrivals: Collection, best_sellers: Collection}
     */
    public function home(): array
    {
        $banners = Banner::live()->where('position', 'home_top')->limit(10)->get();

        $kategori = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(12)
            ->get();

        $baru = fn() => $this->visible()->with(self::LIST_RELATIONS)->limit(self::SECTION_LIMIT);

        return [
            'banners'      => $banners,
            'categories'   => $kategori,
            'featured'     => $baru()->where('is_featured', true)
                ->orderByDesc('published_at')->orderByDesc('id')->get(),
            'new_arrivals' => $baru()
                ->orderByDesc('published_at')->orderByDesc('id')->get(),
            'best_sellers' => $baru()->where('sold_count', '>', 0)
                ->orderByDesc('sold_count')->orderByDesc('id')->get(),
        ];
    }

    /**
     * Produk terkait: kategori sama, selain produk itu sendiri, terlaris dulu.
     * Null jika produk acuan tidak tampil untuk publik.
     */
    public function related(string $slug): ?Collection
    {
        $produk = $this->visible()->where('slug', $slug)->first();

        if ($produk === null) {
            return null;
        }

        return $this->visible()
            ->with(self::LIST_RELATIONS)
            ->where('category_id', $produk->category_id)
            ->where('id', '!=', $produk->id)
            ->orderByDesc('sold_count')
            ->orderByDesc('id')
            ->limit(self::RELATED_LIMIT)
            ->get();
    }

    /** Toko untuk publik beserta jadwal buka dan jumlah produk yang tampil. Null jika tidak aktif. */
    public function findStore(string $slug): ?Store
    {
        $toko = Store::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->with('operatingHours')
            ->first();

        if ($toko === null) {
            return null;
        }

        $toko->setAttribute('products_count', $this->visible()->where('store_id', $toko->id)->count());

        return $toko;
    }

    // ------------------------------------------------------------------ PRIVAT

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'best_selling' => $query->orderByDesc('sold_count')->orderByDesc('id'),
            'price_asc'    => $query->orderBy('price_min')->orderByDesc('id'),
            'price_desc'   => $query->orderByDesc('price_min')->orderByDesc('id'),
            'rating'       => $query->orderByDesc('rating_avg')->orderByDesc('rating_count')->orderByDesc('id'),
            default        => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * ID kategori untuk filter. Kategori utama mencakup subkategorinya yang aktif.
     * Subkategori yang induknya tidak aktif dianggap tidak ada.
     *
     * @return array<int, int>
     */
    private function categoryIds(string $slug): array
    {
        $kategori = Category::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        if ($kategori->parent_id !== null) {
            $indukAktif = Category::query()->whereKey($kategori->parent_id)->where('is_active', true)->exists();

            if (! $indukAktif) {
                throw new ModelNotFoundException;
            }

            return [$kategori->id];
        }

        $anak = Category::query()
            ->where('parent_id', $kategori->id)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        return array_merge([$kategori->id], $anak);
    }

    /** @return array<int, string> */
    private function words(?string $q): array
    {
        if ($q === null || trim($q) === '') {
            return [];
        }

        $kata = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($kata, 0, self::MAX_SEARCH_WORDS);
    }

    /**
     * Tanda % dan _ yang diketik pengguna dianggap huruf biasa, bukan pola pencarian.
     * "!" dipakai sebagai karakter pelarian karena berlaku sama di MySQL dan SQLite.
     */
    private function escapeLike(string $kata): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $kata);
    }
}
