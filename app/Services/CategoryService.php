<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\ImageNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CategoryService
{
    public function __construct(private readonly AuthService $auth) {}

    // ------------------------------------------------------------------ PUBLIK

    /** Kategori utama yang aktif beserta subkategorinya yang aktif. */
    public function publicTree(): Collection
    {
        return Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Satu kategori untuk publik. Null jika tidak ada, tidak aktif, sudah dihapus,
     * atau induknya tidak aktif.
     */
    public function findPublic(string $slug): ?Category
    {
        $kategori = Category::query()->where('slug', $slug)->where('is_active', true)->first();

        if ($kategori === null) {
            return null;
        }

        $induk = null;

        if ($kategori->parent_id !== null) {
            $induk = Category::query()->whereKey($kategori->parent_id)->where('is_active', true)->first();

            if ($induk === null) {
                return null;
            }
        }

        // Induk null untuk kategori utama: tampil sebagai "parent": null.
        $kategori->setRelation('parent', $induk);

        return $kategori->load(['children' => fn($query) => $query->where('is_active', true)]);
    }

    // ------------------------------------------------------------------- ADMIN

    /** Semua kategori utama beserta SEMUA subkategorinya (termasuk yang nonaktif), dengan jumlah produk. */
    public function adminTree(): Collection
    {
        return Category::query()
            ->whereNull('parent_id')
            ->with(['children' => fn($query) => $query->withCount('products')])
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function findByUuid(string $uuid): Category
    {
        return Category::query()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(User $admin, array $data, Request $request): Category
    {
        return DB::transaction(function () use ($admin, $data, $request) {
            $parentId = $this->resolveParentId($data['parent_id'] ?? null, null);

            $kategori = new Category([
                'name'       => $data['name'],
                'slug'       => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'parent_id'  => $parentId,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active'  => $data['is_active'] ?? true,
            ]);
            $kategori->save();

            $this->auth->record($admin->id, 'admin.category_created', $request, ['category' => $kategori->uuid]);

            return $this->reload($kategori);
        });
    }

    /**
     * Mengubah kategori. Slug tidak ikut berubah saat nama diganti; slug hanya
     * berubah jika dikirim secara eksplisit.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(User $admin, Category $kategori, array $data, Request $request): Category
    {
        return DB::transaction(function () use ($admin, $kategori, $data, $request) {
            $kategori = Category::query()->lockForUpdate()->findOrFail($kategori->id);

            $isi = [];

            foreach (['name', 'sort_order', 'is_active'] as $kolom) {
                if (array_key_exists($kolom, $data)) {
                    $isi[$kolom] = $data[$kolom];
                }
            }

            if (array_key_exists('slug', $data) && $data['slug'] !== null) {
                $isi['slug'] = $data['slug'];
            }

            if (array_key_exists('parent_id', $data)) {
                $isi['parent_id'] = $this->resolveParentId($data['parent_id'], $kategori);
            }

            $kategori->fill($isi)->save();

            $this->auth->record($admin->id, 'admin.category_updated', $request, [
                'category' => $kategori->uuid,
                'fields'   => array_keys($isi),
            ]);

            return $this->reload($kategori);
        });
    }

    /**
     * Menghapus kategori (hapus lunak). Ditolak jika masih punya subkategori atau produk
     * (produk yang sudah dihapus pun dihitung). Berkas gambar dibiarkan agar kategori
     * masih bisa dipulihkan.
     *
     * @throws ValidationException
     */
    public function delete(User $admin, Category $kategori, Request $request): void
    {
        DB::transaction(function () use ($admin, $kategori, $request) {
            $kategori = Category::query()->lockForUpdate()->findOrFail($kategori->id);

            if ($kategori->children()->exists()) {
                throw ValidationException::withMessages([
                    'category' => ['Kategori ini masih memiliki subkategori. Hapus atau pindahkan subkategorinya terlebih dahulu.'],
                ]);
            }

            if (Product::withTrashed()->where('category_id', $kategori->id)->exists()) {
                throw ValidationException::withMessages([
                    'category' => ['Kategori ini masih dipakai produk. Nonaktifkan saja jika tidak ingin ditampilkan.'],
                ]);
            }

            $kategori->delete();

            $this->auth->record($admin->id, 'admin.category_deleted', $request, ['category' => $kategori->uuid]);
        });
    }

    /** Mengganti ikon atau gambar kategori. Berkas lama dihapus setelah yang baru tersimpan. */
    public function setImage(User $admin, Category $kategori, string $type, UploadedFile $file, Request $request): Category
    {
        [$kolom, $folder, $sisiMaks] = $this->imageSpec($type);

        $bytes = ImageNormalizer::jpeg($file, 'image', $sisiMaks);
        $baru  = $folder . '/' . Str::uuid()->toString() . '.jpg';
        $lama  = $kategori->{$kolom};
        $disk  = Storage::disk('public');

        $disk->put($baru, $bytes);

        try {
            $kategori->forceFill([$kolom => $baru])->save();
        } catch (Throwable $e) {
            $disk->delete($baru);

            throw $e;
        }

        if ($lama !== null) {
            $disk->delete($lama);
        }

        $this->auth->record($admin->id, 'admin.category_image_changed', $request, [
            'category' => $kategori->uuid,
            'type'     => $type,
        ]);

        return $this->reload($kategori);
    }

    public function removeImage(User $admin, Category $kategori, string $type, Request $request): Category
    {
        [$kolom] = $this->imageSpec($type);

        $lama = $kategori->{$kolom};

        $kategori->forceFill([$kolom => null])->save();

        if ($lama !== null) {
            Storage::disk('public')->delete($lama);
        }

        $this->auth->record($admin->id, 'admin.category_image_removed', $request, [
            'category' => $kategori->uuid,
            'type'     => $type,
        ]);

        return $this->reload($kategori);
    }

    // ------------------------------------------------------------------ PRIVAT

    /**
     * Mengubah UUID induk menjadi ID, sambil menegakkan aturan struktur:
     * induk harus ada, harus kategori utama (maksimal 2 tingkat), bukan kategori itu sendiri,
     * dan kategori yang punya subkategori tidak boleh menjadi subkategori.
     * Harus dipanggil di dalam transaksi (baris induk dikunci).
     *
     * @throws ValidationException
     */
    private function resolveParentId(?string $parentUuid, ?Category $self): ?int
    {
        if ($parentUuid === null) {
            return null;
        }

        $induk = Category::query()->where('uuid', $parentUuid)->lockForUpdate()->first();

        if ($induk === null) {
            throw ValidationException::withMessages(['parent_id' => ['Kategori induk tidak ditemukan.']]);
        }

        if ($self !== null && $induk->id === $self->id) {
            throw ValidationException::withMessages(['parent_id' => ['Kategori tidak boleh menjadi induk dirinya sendiri.']]);
        }

        if ($induk->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => ['Kategori induk harus kategori utama. Struktur kategori maksimal 2 tingkat.'],
            ]);
        }

        if ($self !== null && $self->children()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => ['Kategori yang memiliki subkategori tidak bisa dijadikan subkategori.'],
            ]);
        }

        return $induk->id;
    }

    /** Slug unik dari nama; jika sudah dipakai (termasuk kategori yang dihapus), ditambah akhiran acak. */
    private function uniqueSlug(string $name): string
    {
        $dasar = Str::limit(Str::slug($name) ?: 'kategori', 100, '');
        $slug  = $dasar;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $dasar . '-' . Str::lower(Str::random(5));
        }

        return $slug;
    }

    private function reload(Category $kategori): Category
    {
        return $kategori->fresh(['parent'])->loadCount('products');
    }

    /** @return array{0: string, 1: string, 2: int} kolom, folder, sisi terpanjang (piksel) */
    private function imageSpec(string $type): array
    {
        return match ($type) {
            'icon'  => ['icon_path', 'categories/icons', 256],
            'image' => ['image_path', 'categories/images', 1200],
        };
    }
}
