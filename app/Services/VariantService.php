<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\VariantRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VariantService
{
    /** Kolom varian yang boleh diubah seller. Stok tidak termasuk: stok hanya berubah lewat adjustStock(). */
    private const EDITABLE = [
        'name',
        'sku',
        'options',
        'price',
        'discount_price',
        'discount_starts_at',
        'discount_ends_at',
        'weight_gram',
        'length_cm',
        'width_cm',
        'height_cm',
        'is_active',
    ];

    public function __construct(private readonly AuthService $auth) {}

    /**
     * Menyiapkan dan memeriksa daftar varian untuk produk BARU.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, array<string, mixed>> varian yang sudah dinormalkan
     *
     * @throws ValidationException
     */
    public function prepareSet(array $variants): array
    {
        $hasil   = [];
        $errors  = [];
        $namaAda = [];
        $skuAda  = [];

        foreach (array_values($variants) as $i => $mentah) {
            $v = $this->normalize($mentah);

            try {
                VariantRules::assertConsistent($v, "variants.{$i}.");
            } catch (ValidationException $e) {
                foreach ($e->errors() as $kunci => $pesan) {
                    $errors[$kunci] = $pesan;
                }
            }

            $nama = mb_strtolower($v['name']);

            if (isset($namaAda[$nama])) {
                $errors["variants.{$i}.name"][] = 'Nama varian tidak boleh kembar dalam satu produk.';
            }
            $namaAda[$nama] = true;

            if ($v['sku'] !== null) {
                $sku = mb_strtolower($v['sku']);

                if (isset($skuAda[$sku])) {
                    $errors["variants.{$i}.sku"][] = 'SKU tidak boleh kembar dalam satu produk.';
                }
                $skuAda[$sku] = true;
            }

            $hasil[] = $v;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $hasil;
    }

    /** Membuat satu varian (dan mencatat stok awal). Harus dipanggil di dalam transaksi. */
    public function createRecord(Product $produk, array $v, int $urutan, User $user): ProductVariant
    {
        $varian = $produk->variants()->create([
            'sku'                => $v['sku'],
            'name'               => $v['name'],
            'options'            => $v['options'],
            'price'              => $v['price'],
            'discount_price'     => $v['discount_price'],
            'discount_starts_at' => $v['discount_starts_at'],
            'discount_ends_at'   => $v['discount_ends_at'],
            'weight_gram'        => $v['weight_gram'],
            'length_cm'          => $v['length_cm'],
            'width_cm'           => $v['width_cm'],
            'height_cm'          => $v['height_cm'],
            'is_active'          => $v['is_active'],
            'sort_order'         => $urutan,
        ]);

        if ($v['stock'] > 0) {
            $varian->adjustStock($v['stock'], 'initial', null, $user->id, 'Stok awal');
        }

        return $varian;
    }

    /**
     * Menambah varian ke produk yang sudah ada.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function add(Product $produk, User $user, array $data, Request $request): Product
    {
        $v = $this->normalize($data);

        return DB::transaction(function () use ($produk, $user, $v, $request) {
            $p = Product::query()->lockForUpdate()->findOrFail($produk->id);

            if (ProductVariant::query()->where('product_id', $p->id)->count() >= VariantRules::MAX_VARIANTS) {
                throw ValidationException::withMessages([
                    'variants' => ['Maksimal ' . VariantRules::MAX_VARIANTS . ' varian per produk.'],
                ]);
            }

            VariantRules::assertConsistent($v);
            $this->assertUnique($p->id, $v['name'], $v['sku'], null);

            $urutan = ProductVariant::query()->where('product_id', $p->id)->max('sort_order');
            $varian = $this->createRecord($p, $v, $urutan === null ? 0 : ((int) $urutan) + 1, $user);

            $p->refreshPriceRange();

            $this->auth->record($user->id, 'product.variant_added', $request, [
                'product' => $p->uuid,
                'variant' => $varian->uuid,
            ]);

            return $this->reload($p);
        });
    }

    /**
     * Mengubah varian. Hanya kolom yang dikirim yang berubah, dan nilai akhirnya diperiksa utuh.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Product $produk, string $varianUuid, User $user, array $data, Request $request): Product
    {
        return DB::transaction(function () use ($produk, $varianUuid, $user, $data, $request) {
            $p      = Product::query()->lockForUpdate()->findOrFail($produk->id);
            $varian = ProductVariant::query()
                ->where('product_id', $p->id)
                ->where('uuid', $varianUuid)
                ->lockForUpdate()
                ->firstOrFail();

            $baru = [];

            foreach (self::EDITABLE as $kolom) {
                if (array_key_exists($kolom, $data)) {
                    $baru[$kolom] = $data[$kolom];
                }
            }

            if (array_key_exists('name', $baru)) {
                $baru['name'] = trim((string) $baru['name']);
            }

            if (array_key_exists('sku', $baru)) {
                $sku         = trim((string) ($baru['sku'] ?? ''));
                $baru['sku'] = $sku === '' ? null : $sku;
            }

            if (array_key_exists('options', $baru) && empty($baru['options'])) {
                $baru['options'] = null;
            }

            foreach (['price', 'discount_price', 'weight_gram', 'length_cm', 'width_cm', 'height_cm'] as $angka) {
                if (array_key_exists($angka, $baru) && $baru[$angka] !== null) {
                    $baru[$angka] = (int) $baru[$angka];
                }
            }

            if (array_key_exists('is_active', $baru)) {
                $baru['is_active'] = filter_var($baru['is_active'], FILTER_VALIDATE_BOOLEAN);
            }

            // Harga diskon dikosongkan: masa diskonnya ikut dikosongkan.
            if (array_key_exists('discount_price', $baru) && $baru['discount_price'] === null) {
                $baru['discount_starts_at'] = null;
                $baru['discount_ends_at']   = null;
            }

            $akhir = [];

            foreach (self::EDITABLE as $kolom) {
                $akhir[$kolom] = array_key_exists($kolom, $baru) ? $baru[$kolom] : $varian->{$kolom};
            }

            VariantRules::assertConsistent($akhir);
            $this->assertUnique($p->id, $akhir['name'], $akhir['sku'], $varian->id);

            if (($baru['is_active'] ?? true) === false && $varian->is_active && $p->status === Product::STATUS_ACTIVE) {
                if (! $this->hasOtherActive($p->id, $varian->id)) {
                    throw ValidationException::withMessages([
                        'is_active' => ['Produk yang sedang tayang harus memiliki minimal 1 varian aktif.'],
                    ]);
                }
            }

            $varian->fill($baru)->save();
            $p->refreshPriceRange();

            $this->auth->record($user->id, 'product.variant_updated', $request, [
                'product' => $p->uuid,
                'variant' => $varian->uuid,
                'fields'  => array_keys($baru),
            ]);

            return $this->reload($p);
        });
    }

    /**
     * Menghapus varian. Ditolak jika ini satu-satunya varian, pernah dibeli,
     * atau varian aktif terakhir dari produk yang sedang tayang.
     *
     * @throws ValidationException
     */
    public function delete(Product $produk, string $varianUuid, User $user, Request $request): Product
    {
        return DB::transaction(function () use ($produk, $varianUuid, $user, $request) {
            $p      = Product::query()->lockForUpdate()->findOrFail($produk->id);
            $varian = ProductVariant::query()
                ->where('product_id', $p->id)
                ->where('uuid', $varianUuid)
                ->lockForUpdate()
                ->firstOrFail();

            if (ProductVariant::query()->where('product_id', $p->id)->count() <= 1) {
                throw ValidationException::withMessages(['variant' => ['Produk harus memiliki minimal 1 varian.']]);
            }

            if (OrderItem::query()->where('variant_id', $varian->id)->exists()) {
                throw ValidationException::withMessages([
                    'variant' => ['Varian ini pernah dibeli dan tidak bisa dihapus. Nonaktifkan saja.'],
                ]);
            }

            if ($varian->is_active && $p->status === Product::STATUS_ACTIVE && ! $this->hasOtherActive($p->id, $varian->id)) {
                throw ValidationException::withMessages([
                    'variant' => ['Produk yang sedang tayang harus memiliki minimal 1 varian aktif.'],
                ]);
            }

            $uuid = $varian->uuid;

            $varian->delete();
            $p->refreshPriceRange();

            $this->auth->record($user->id, 'product.variant_removed', $request, [
                'product' => $p->uuid,
                'variant' => $uuid,
            ]);

            return $this->reload($p);
        });
    }

    /**
     * Mengubah stok sebesar $delta (positif menambah, negatif mengurangi) lewat
     * ProductVariant::adjustStock(), sehingga stok tidak pernah minus dan selalu tercatat.
     *
     * @throws ValidationException
     */
    public function adjustStock(Product $produk, string $varianUuid, User $user, int $delta, ?string $note, Request $request): Product
    {
        return DB::transaction(function () use ($produk, $varianUuid, $user, $delta, $note, $request) {
            $p      = Product::query()->lockForUpdate()->findOrFail($produk->id);
            $varian = ProductVariant::query()
                ->where('product_id', $p->id)
                ->where('uuid', $varianUuid)
                ->lockForUpdate()
                ->firstOrFail();

            $baru = (int) $varian->stock + $delta;

            if ($baru > VariantRules::MAX_STOCK) {
                throw ValidationException::withMessages([
                    'delta' => ['Stok melebihi batas ' . number_format(VariantRules::MAX_STOCK, 0, ',', '.') . '.'],
                ]);
            }

            $pesanKurang = 'Stok tidak cukup untuk dikurangi sebanyak ' . abs($delta) . '. Stok saat ini: ' . (int) $varian->stock . '.';

            if ($baru < 0) {
                throw ValidationException::withMessages(['delta' => [$pesanKurang]]);
            }

            if (! $varian->adjustStock($delta, 'manual', null, $user->id, $note)) {
                throw ValidationException::withMessages(['delta' => [$pesanKurang]]);
            }

            $this->auth->record($user->id, 'product.stock_adjusted', $request, [
                'product'     => $p->uuid,
                'variant'     => $varian->uuid,
                'delta'       => $delta,
                'stock_after' => (int) $varian->stock,
            ]);

            return $this->reload($p);
        });
    }

    /**
     * Mengubah masukan mentah menjadi bentuk yang seragam.
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function normalize(array $v): array
    {
        $nama  = trim((string) ($v['name'] ?? ''));
        $sku   = trim((string) ($v['sku'] ?? ''));
        $opsi  = $v['options'] ?? null;
        $angka = fn(string $kunci) => isset($v[$kunci]) ? (int) $v[$kunci] : null;

        return [
            'name'               => $nama === '' ? 'Default' : $nama,
            'sku'                => $sku === '' ? null : $sku,
            'options'            => empty($opsi) ? null : $opsi,
            'price'              => (int) ($v['price'] ?? 0),
            'discount_price'     => $angka('discount_price'),
            'discount_starts_at' => $v['discount_starts_at'] ?? null,
            'discount_ends_at'   => $v['discount_ends_at'] ?? null,
            'weight_gram'        => (int) ($v['weight_gram'] ?? 0),
            'length_cm'          => $angka('length_cm'),
            'width_cm'           => $angka('width_cm'),
            'height_cm'          => $angka('height_cm'),
            'is_active'          => array_key_exists('is_active', $v) ? filter_var($v['is_active'], FILTER_VALIDATE_BOOLEAN) : true,
            'stock'              => (int) ($v['stock'] ?? 0),
        ];
    }

    /**
     * Nama dan SKU harus unik dalam satu produk (tanpa membedakan huruf besar kecil).
     *
     * @throws ValidationException
     */
    private function assertUnique(int $produkId, string $nama, ?string $sku, ?int $abaikanId): void
    {
        $dasar = ProductVariant::query()
            ->where('product_id', $produkId)
            ->when($abaikanId !== null, fn($w) => $w->where('id', '!=', $abaikanId));

        $errors = [];

        if ((clone $dasar)->whereRaw('lower(name) = ?', [mb_strtolower($nama)])->exists()) {
            $errors['name'][] = 'Nama varian sudah dipakai produk ini.';
        }

        if ($sku !== null && (clone $dasar)->whereRaw('lower(sku) = ?', [mb_strtolower($sku)])->exists()) {
            $errors['sku'][] = 'SKU sudah dipakai produk ini.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function hasOtherActive(int $produkId, int $varianId): bool
    {
        return ProductVariant::query()
            ->where('product_id', $produkId)
            ->where('id', '!=', $varianId)
            ->where('is_active', true)
            ->exists();
    }

    private function reload(Product $produk): Product
    {
        return $produk->fresh(['category', 'brand', 'images', 'variants']);
    }
}
