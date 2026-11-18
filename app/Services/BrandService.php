<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Product;
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

class BrandService
{
    /** Sisi terpanjang logo brand setelah diproses (piksel). */
    public const LOGO_MAX_SIDE = 512;

    public function __construct(private readonly AuthService $auth) {}

    /**
     * Daftar brand berhalaman.
     *
     * @param  bool  $publik  true: hanya yang aktif. false: semua (admin), boleh difilter $aktif, dan menyertakan jumlah produk.
     */
    public function paginate(?string $q, ?bool $aktif, int $perPage, bool $publik): LengthAwarePaginator
    {
        $query = Brand::query()
            ->when($publik, fn($w) => $w->where('is_active', true))
            ->when(! $publik && $aktif !== null, fn($w) => $w->where('is_active', $aktif))
            ->when($q !== null && $q !== '', function ($w) use ($q) {
                // Tanda % dan _ yang diketik pengguna dianggap huruf biasa, bukan pola pencarian.
                // "!" dipakai sebagai karakter pelarian karena berlaku sama di MySQL dan SQLite.
                $aman = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
                $w->whereRaw("name LIKE ? ESCAPE '!'", ['%' . $aman . '%']);
            })
            ->when(! $publik, fn($w) => $w->withCount('products'))
            ->orderBy('name')
            ->orderBy('id');

        return $query->paginate($perPage);
    }

    public function findByUuid(string $uuid): Brand
    {
        return Brand::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $admin, array $data, Request $request): Brand
    {
        return DB::transaction(function () use ($admin, $data, $request) {
            $brand = new Brand([
                'name'      => $data['name'],
                'slug'      => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'is_active' => $data['is_active'] ?? true,
            ]);
            $brand->save();

            $this->auth->record($admin->id, 'admin.brand_created', $request, ['brand' => $brand->uuid]);

            return $this->reload($brand);
        });
    }

    /**
     * Mengubah brand. Slug tidak ikut berubah saat nama diganti.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $admin, Brand $brand, array $data, Request $request): Brand
    {
        return DB::transaction(function () use ($admin, $brand, $data, $request) {
            $brand = Brand::query()->lockForUpdate()->findOrFail($brand->id);

            $isi = [];

            foreach (['name', 'is_active'] as $kolom) {
                if (array_key_exists($kolom, $data)) {
                    $isi[$kolom] = $data[$kolom];
                }
            }

            if (array_key_exists('slug', $data) && $data['slug'] !== null) {
                $isi['slug'] = $data['slug'];
            }

            $brand->fill($isi)->save();

            $this->auth->record($admin->id, 'admin.brand_updated', $request, [
                'brand'  => $brand->uuid,
                'fields' => array_keys($isi),
            ]);

            return $this->reload($brand);
        });
    }

    /**
     * Menghapus brand. Ditolak jika masih dipakai produk (termasuk yang sudah dihapus);
     * nonaktifkan saja agar produknya tidak kehilangan brand.
     *
     * @throws ValidationException
     */
    public function delete(User $admin, Brand $brand, Request $request): void
    {
        DB::transaction(function () use ($admin, $brand, $request) {
            $brand = Brand::query()->lockForUpdate()->findOrFail($brand->id);

            if (Product::withTrashed()->where('brand_id', $brand->id)->exists()) {
                throw ValidationException::withMessages([
                    'brand' => ['Brand ini masih dipakai produk. Nonaktifkan saja jika tidak ingin ditampilkan.'],
                ]);
            }

            $logo = $brand->logo_path;
            $uuid = $brand->uuid;

            $brand->delete();

            if ($logo !== null) {
                Storage::disk('public')->delete($logo);
            }

            $this->auth->record($admin->id, 'admin.brand_deleted', $request, ['brand' => $uuid]);
        });
    }

    /** Mengganti logo brand. Berkas lama dihapus setelah yang baru tersimpan. */
    public function setLogo(User $admin, Brand $brand, UploadedFile $file, Request $request): Brand
    {
        $bytes = ImageNormalizer::jpeg($file, 'image', self::LOGO_MAX_SIDE);
        $baru  = 'brands/logos/' . Str::uuid()->toString() . '.jpg';
        $lama  = $brand->logo_path;
        $disk  = Storage::disk('public');

        $disk->put($baru, $bytes);

        try {
            $brand->forceFill(['logo_path' => $baru])->save();
        } catch (Throwable $e) {
            $disk->delete($baru);

            throw $e;
        }

        if ($lama !== null) {
            $disk->delete($lama);
        }

        $this->auth->record($admin->id, 'admin.brand_logo_changed', $request, ['brand' => $brand->uuid]);

        return $this->reload($brand);
    }

    public function removeLogo(User $admin, Brand $brand, Request $request): Brand
    {
        $lama = $brand->logo_path;

        $brand->forceFill(['logo_path' => null])->save();

        if ($lama !== null) {
            Storage::disk('public')->delete($lama);
        }

        $this->auth->record($admin->id, 'admin.brand_logo_removed', $request, ['brand' => $brand->uuid]);

        return $this->reload($brand);
    }

    /** Slug unik dari nama; jika sudah dipakai, ditambah akhiran acak. */
    private function uniqueSlug(string $name): string
    {
        $dasar = Str::limit(Str::slug($name) ?: 'brand', 100, '');
        $slug  = $dasar;

        while (Brand::query()->where('slug', $slug)->exists()) {
            $slug = $dasar . '-' . Str::lower(Str::random(5));
        }

        return $slug;
    }

    private function reload(Brand $brand): Brand
    {
        return $brand->fresh()->loadCount('products');
    }
}
