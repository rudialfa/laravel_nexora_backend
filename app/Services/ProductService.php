<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Support\ImageNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductService
{
    public const MAX_IMAGES     = 9;
    public const IMAGE_MAX_SIDE = 1600;

    public function __construct(
        private readonly AuthService $auth,
        private readonly VariantService $variants
    ) {}

    // ------------------------------------------------------------------ SELLER

    public function paginate(Store $store, ?string $status, ?string $q, int $perPage): LengthAwarePaginator
    {
        return $store->products()
            ->with(['category', 'brand', 'images', 'variants'])
            ->when($status !== null && $status !== '', fn($w) => $w->where('status', $status))
            ->when($q !== null && $q !== '', function ($w) use ($q) {
                // Tanda % dan _ yang diketik pengguna dianggap huruf biasa, bukan pola pencarian.
                // "!" dipakai sebagai karakter pelarian karena berlaku sama di MySQL dan SQLite.
                $aman = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
                $w->whereRaw("name LIKE ? ESCAPE '!'", ['%' . $aman . '%']);
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** Mencari produk HANYA di antara milik toko ini. Milik toko lain atau yang tidak ada sama-sama 404. */
    public function findOwned(Store $store, string $uuid): Product
    {
        return $store->products()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Membuat produk berstatus draf beserta variannya (dan stok awal) dalam satu transaksi.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Store $store, User $user, array $data, Request $request): Product
    {
        $kategori = $this->resolveCategory($data['category_id']);
        $brand    = ($data['brand_id'] ?? null) !== null ? $this->resolveBrand($data['brand_id']) : null;
        $varian   = $this->variants->prepareSet($data['variants']);

        return DB::transaction(function () use ($store, $user, $data, $request, $kategori, $brand, $varian) {
            $produk = new Product([
                'category_id' => $kategori->id,
                'brand_id'    => $brand?->id,
                'name'        => $data['name'],
                'slug'        => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
            ]);
            // store_id dan status tidak bisa diisi massal: ditentukan server.
            $produk->store_id = $store->id;
            $produk->save();

            foreach ($varian as $i => $v) {
                $this->variants->createRecord($produk, $v, $i, $user);
            }

            $produk->refreshPriceRange();

            $this->auth->record($user->id, 'product.created', $request, ['product' => $produk->uuid]);

            return $this->reload($produk);
        });
    }

    /**
     * Mengubah data produk. Slug tidak ikut berubah saat nama diganti.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(User $user, Product $produk, array $data, Request $request): Product
    {
        return DB::transaction(function () use ($user, $produk, $data, $request) {
            $p    = Product::query()->lockForUpdate()->findOrFail($produk->id);
            $isi  = [];

            foreach (['name', 'description'] as $kolom) {
                if (array_key_exists($kolom, $data)) {
                    $isi[$kolom] = $data[$kolom];
                }
            }

            if (array_key_exists('category_id', $data)) {
                $isi['category_id'] = $this->resolveCategory($data['category_id'])->id;
            }

            if (array_key_exists('brand_id', $data)) {
                $isi['brand_id'] = $data['brand_id'] === null ? null : $this->resolveBrand($data['brand_id'])->id;
            }

            $p->fill($isi)->save();

            $this->auth->record($user->id, 'product.updated', $request, [
                'product' => $p->uuid,
                'fields'  => array_keys($isi),
            ]);

            return $this->reload($p);
        });
    }

    /** Menghapus produk (hapus lunak). Pesanan lama tetap menyimpan salinan datanya. */
    public function delete(User $user, Product $produk, Request $request): void
    {
        DB::transaction(function () use ($user, $produk, $request) {
            $p = Product::query()->lockForUpdate()->findOrFail($produk->id);

            $p->delete();

            $this->auth->record($user->id, 'product.deleted', $request, ['product' => $p->uuid]);
        });
    }

    /**
     * Menayangkan produk. Syarat: minimal 1 foto, 1 varian aktif, dan kategori aktif.
     * Produk yang sudah tayang dibiarkan apa adanya (idempoten).
     *
     * @throws ValidationException
     */
    public function publish(User $user, Product $produk, Request $request): Product
    {
        return DB::transaction(function () use ($user, $produk, $request) {
            $p = Product::query()->lockForUpdate()->findOrFail($produk->id);

            if ($p->status === Product::STATUS_SUSPENDED) {
                throw ValidationException::withMessages([
                    'status' => ['Produk ini ditangguhkan oleh admin dan tidak dapat ditayangkan.'],
                ]);
            }

            if ($p->status === Product::STATUS_ACTIVE) {
                return $this->reload($p);
            }

            if (! ProductImage::query()->where('product_id', $p->id)->exists()) {
                throw ValidationException::withMessages([
                    'images' => ['Tambahkan minimal 1 foto produk sebelum menayangkan.'],
                ]);
            }

            if (! ProductVariant::query()->where('product_id', $p->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'variants' => ['Produk harus memiliki minimal 1 varian aktif sebelum ditayangkan.'],
                ]);
            }

            if (! Category::query()->whereKey($p->category_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'category_id' => ['Kategori produk ini tidak aktif. Pilih kategori lain terlebih dahulu.'],
                ]);
            }

            $p->forceFill([
                'status'       => Product::STATUS_ACTIVE,
                'published_at' => $p->published_at ?? now(),
            ])->save();

            $this->auth->record($user->id, 'product.published', $request, ['product' => $p->uuid]);

            return $this->reload($p);
        });
    }

    /** Menghentikan penayangan. Produk yang belum tayang dibiarkan apa adanya (idempoten). */
    public function unpublish(User $user, Product $produk, Request $request): Product
    {
        return DB::transaction(function () use ($user, $produk, $request) {
            $p = Product::query()->lockForUpdate()->findOrFail($produk->id);

            if ($p->status === Product::STATUS_SUSPENDED) {
                throw ValidationException::withMessages([
                    'status' => ['Produk ini ditangguhkan oleh admin dan statusnya tidak dapat diubah oleh seller.'],
                ]);
            }

            if ($p->status !== Product::STATUS_ACTIVE) {
                return $this->reload($p);
            }

            $p->forceFill(['status' => Product::STATUS_INACTIVE])->save();

            $this->auth->record($user->id, 'product.unpublished', $request, ['product' => $p->uuid]);

            return $this->reload($p);
        });
    }

    /**
     * Menambah foto produk. Gambar diproses ulang menjadi JPEG; berkas dibersihkan jika gagal.
     *
     * @throws ValidationException
     */
    public function addImage(User $user, Product $produk, UploadedFile $file, Request $request): Product
    {
        $bytes = ImageNormalizer::jpeg($file, 'image', self::IMAGE_MAX_SIDE);
        $path  = 'products/' . Str::uuid()->toString() . '.jpg';
        $disk  = Storage::disk('public');

        $disk->put($path, $bytes);

        try {
            return DB::transaction(function () use ($user, $produk, $path, $request) {
                $p = Product::query()->lockForUpdate()->findOrFail($produk->id);

                if (ProductImage::query()->where('product_id', $p->id)->count() >= self::MAX_IMAGES) {
                    throw ValidationException::withMessages([
                        'image' => ['Maksimal ' . self::MAX_IMAGES . ' foto per produk.'],
                    ]);
                }

                $urutan = ProductImage::query()->where('product_id', $p->id)->max('sort_order');

                $gambar = $p->images()->create([
                    'path'       => $path,
                    'sort_order' => $urutan === null ? 0 : ((int) $urutan) + 1,
                ]);

                $this->auth->record($user->id, 'product.image_added', $request, [
                    'product' => $p->uuid,
                    'image'   => $gambar->uuid,
                ]);

                return $this->reload($p);
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /**
     * Menghapus foto produk. Foto terakhir dari produk yang sedang tayang tidak bisa dihapus.
     *
     * @throws ValidationException
     */
    public function removeImage(User $user, Product $produk, string $imageUuid, Request $request): Product
    {
        $hapusBerkas = null;

        $hasil = DB::transaction(function () use ($user, $produk, $imageUuid, $request, &$hapusBerkas) {
            $p      = Product::query()->lockForUpdate()->findOrFail($produk->id);
            $gambar = ProductImage::query()->where('product_id', $p->id)->where('uuid', $imageUuid)->firstOrFail();

            if ($p->status === Product::STATUS_ACTIVE && ProductImage::query()->where('product_id', $p->id)->count() <= 1) {
                throw ValidationException::withMessages([
                    'image' => ['Foto terakhir produk yang sedang tayang tidak bisa dihapus. Hentikan penayangan terlebih dahulu.'],
                ]);
            }

            $hapusBerkas = $gambar->path;
            $uuid        = $gambar->uuid;

            $gambar->delete();

            $this->auth->record($user->id, 'product.image_removed', $request, [
                'product' => $p->uuid,
                'image'   => $uuid,
            ]);

            return $this->reload($p);
        });

        // Berkas dihapus setelah transaksi berhasil, sehingga tidak ada foto yang hilang jika transaksi gagal.
        if ($hapusBerkas !== null) {
            Storage::disk('public')->delete($hapusBerkas);
        }

        return $hasil;
    }

    // ------------------------------------------------------------------ PUBLIK

    /**
     * Produk untuk publik. Null jika tidak tayang, tokonya tidak aktif, atau kategorinya tidak aktif.
     * Brand yang tidak aktif disembunyikan (produk tetap tampil).
     */
    public function findPublic(string $slug): ?Product
    {
        return Product::query()
            ->active()
            ->where('slug', $slug)
            ->whereHas('store', fn($w) => $w->where('status', 'active'))
            ->whereHas('category', fn($w) => $w->where('is_active', true))
            ->with([
                'category',
                'brand' => fn($q) => $q->where('is_active', true),
                'store',
                'images',
                'variants' => fn($q) => $q->where('is_active', true),
            ])
            ->first();
    }

    // ------------------------------------------------------------------ PRIVAT

    /** @throws ValidationException */
    private function resolveCategory(string $uuid): Category
    {
        $kategori = Category::query()->where('uuid', $uuid)->where('is_active', true)->first();

        if ($kategori === null) {
            throw ValidationException::withMessages(['category_id' => ['Kategori tidak ditemukan atau tidak aktif.']]);
        }

        return $kategori;
    }

    /** @throws ValidationException */
    private function resolveBrand(string $uuid): Brand
    {
        $brand = Brand::query()->where('uuid', $uuid)->where('is_active', true)->first();

        if ($brand === null) {
            throw ValidationException::withMessages(['brand_id' => ['Brand tidak ditemukan atau tidak aktif.']]);
        }

        return $brand;
    }

    /** Slug unik dari nama; jika sudah dipakai (termasuk produk yang dihapus), ditambah akhiran acak. */
    private function uniqueSlug(string $name): string
    {
        $dasar = Str::limit(Str::slug($name) ?: 'produk', 150, '');
        $slug  = $dasar;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $dasar . '-' . Str::lower(Str::random(6));
        }

        return $slug;
    }

    private function reload(Product $produk): Product
    {
        return $produk->fresh(['category', 'brand', 'images', 'variants']);
    }
}
