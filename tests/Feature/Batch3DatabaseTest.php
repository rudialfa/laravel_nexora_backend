<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Menguji tabel dan relasi Batch 3 (katalog produk).
 * Berjalan di SQLite memori, tidak menyentuh MySQL.
 */
class Batch3DatabaseTest extends TestCase
{
    use RefreshDatabase;

    private int $urut = 0;

    private function buatUser(): User
    {
        $this->urut++;

        return User::create([
            'name'     => "User {$this->urut}",
            'email'    => "user{$this->urut}@nexora.test",
            'password' => 'rahasia123',
        ]);
    }

    private function buatToko(): Store
    {
        $pemilik = $this->buatUser();

        $toko = new Store([
            'name'        => 'Toko Budi',
            'slug'        => "toko-{$this->urut}",
            'phone'       => '628123456789',
            'province'    => 'DI Yogyakarta',
            'city'        => 'Sleman',
            'district'    => 'Depok',
            'postal_code' => '55281',
            'street'      => 'Jl. Contoh No. 1',
        ]);
        $toko->owner_id = $pemilik->id;
        $toko->save();

        return $toko->fresh();
    }

    private function buatKategori(string $nama = 'Fashion', ?int $parentId = null): Category
    {
        $this->urut++;

        return Category::create([
            'name'      => $nama,
            'slug'      => Str::slug($nama) . '-' . $this->urut,
            'parent_id' => $parentId,
        ]);
    }

    private function buatProduk(?Store $toko = null, ?Category $kategori = null): Product
    {
        $toko     ??= $this->buatToko();
        $kategori ??= $this->buatKategori();
        $this->urut++;

        $produk = new Product([
            'name'        => 'Kaos Polos',
            'slug'        => "kaos-polos-{$this->urut}",
            'description' => 'Kaos katun nyaman',
            'category_id' => $kategori->id,
        ]);
        $produk->store_id = $toko->id;
        $produk->save();

        return $produk->fresh();
    }

    private function buatVarian(Product $produk, array $override = []): ProductVariant
    {
        return $produk->variants()->create(array_merge([
            'name'        => 'Default',
            'price'       => 100000,
            'weight_gram' => 200,
        ], $override));
    }

    public function test_kategori_punya_subkategori_dan_slug_unik(): void
    {
        $induk = Category::create(['name' => 'Fashion', 'slug' => 'fashion']);
        $anak  = Category::create(['name' => 'Pria', 'slug' => 'fashion-pria', 'parent_id' => $induk->id]);

        $this->assertSame(1, $induk->children()->count());
        $this->assertSame($induk->id, $anak->parent->id);
        $this->assertSame(36, strlen($induk->fresh()->uuid));

        $this->expectException(QueryException::class);
        Category::create(['name' => 'Fashion Lain', 'slug' => 'fashion']);
    }

    public function test_kategori_yang_punya_produk_tidak_bisa_dihapus_permanen(): void
    {
        $kategori = $this->buatKategori();
        $this->buatProduk(null, $kategori);

        $this->expectException(QueryException::class);
        $kategori->forceDelete();
    }

    public function test_toko_yang_punya_produk_tidak_bisa_dihapus_permanen(): void
    {
        $toko = $this->buatToko();
        $this->buatProduk($toko);

        $this->expectException(QueryException::class);
        $toko->forceDelete();
    }

    public function test_brand_dihapus_produk_tetap_ada(): void
    {
        $brand  = Brand::create(['name' => 'Nexora Wear', 'slug' => 'nexora-wear']);
        $produk = $this->buatProduk();
        $produk->brand_id = $brand->id;
        $produk->save();

        $brand->delete();

        $this->assertNull($produk->fresh()->brand_id);
        $this->assertDatabaseHas('products', ['id' => $produk->id]);
    }

    public function test_produk_punya_uuid_status_draft_dan_relasi(): void
    {
        $toko     = $this->buatToko();
        $kategori = $this->buatKategori();
        $produk   = $this->buatProduk($toko, $kategori);

        $this->assertSame(36, strlen($produk->uuid));
        $this->assertSame(Product::STATUS_DRAFT, $produk->status);
        $this->assertFalse($produk->is_featured);
        $this->assertSame($toko->id, $produk->store->id);
        $this->assertSame($kategori->id, $produk->category->id);
        $this->assertSame(1, $toko->products()->count());
        $this->assertSame(0, Product::active()->count());
    }

    public function test_kolom_sensitif_produk_tidak_bisa_diisi_massal(): void
    {
        $toko     = $this->buatToko();
        $kategori = $this->buatKategori();

        $produk = new Product([
            'name' => 'Produk Nakal',
            'slug' => 'produk-nakal',
            'category_id' => $kategori->id,
            // Semua di bawah ini harus diabaikan oleh mass assignment:
            'store_id'    => 999,
            'status'      => 'active',
            'is_featured' => true,
            'sold_count'  => 500,
            'price_min'   => 1,
        ]);

        $this->assertNull($produk->store_id);

        $produk->store_id = $toko->id;
        $produk->save();
        $produk = $produk->fresh();

        $this->assertSame('draft', $produk->status);
        $this->assertFalse($produk->is_featured);
        $this->assertSame(0, $produk->sold_count);
        $this->assertSame(0, $produk->price_min);
    }

    public function test_harga_efektif_varian_mengikuti_aturan_diskon(): void
    {
        $normal = new ProductVariant(['price' => 100000]);
        $this->assertSame(100000, $normal->effectivePrice());

        $aktif = new ProductVariant(['price' => 100000, 'discount_price' => 80000]);
        $this->assertSame(80000, $aktif->effectivePrice());

        $berlaku = new ProductVariant([
            'price' => 100000,
            'discount_price' => 70000,
            'discount_starts_at' => now()->subDay(),
            'discount_ends_at' => now()->addDay(),
        ]);
        $this->assertSame(70000, $berlaku->effectivePrice());

        $kedaluwarsa = new ProductVariant([
            'price' => 100000,
            'discount_price' => 70000,
            'discount_starts_at' => now()->subDays(5),
            'discount_ends_at' => now()->subDay(),
        ]);
        $this->assertSame(100000, $kedaluwarsa->effectivePrice());

        $belumMulai = new ProductVariant([
            'price' => 100000,
            'discount_price' => 70000,
            'discount_starts_at' => now()->addDay(),
            'discount_ends_at' => now()->addDays(5),
        ]);
        $this->assertSame(100000, $belumMulai->effectivePrice());

        // Diskon yang tidak lebih murah dari harga normal diabaikan.
        $tidakMasuk = new ProductVariant(['price' => 100000, 'discount_price' => 100000]);
        $this->assertSame(100000, $tidakMasuk->effectivePrice());
    }

    public function test_stok_tidak_bisa_diisi_massal(): void
    {
        $varian = $this->buatVarian($this->buatProduk(), ['stock' => 999]);

        $this->assertSame(0, $varian->fresh()->stock);
        $this->assertSame(36, strlen($varian->fresh()->uuid));
    }

    public function test_sku_unik_dalam_satu_produk_tapi_boleh_sama_antar_produk(): void
    {
        $produkA = $this->buatProduk();
        $produkB = $this->buatProduk();

        $this->buatVarian($produkA, ['sku' => 'KAOS-M']);
        $this->buatVarian($produkB, ['sku' => 'KAOS-M']);   // produk lain: boleh

        $this->assertSame(1, $produkA->variants()->count());
        $this->assertSame(1, $produkB->variants()->count());

        $this->expectException(QueryException::class);
        $this->buatVarian($produkA, ['sku' => 'KAOS-M']);   // produk sama: ditolak
    }

    public function test_stok_tidak_boleh_minus_dan_setiap_perubahan_tercatat(): void
    {
        $varian = $this->buatVarian($this->buatProduk());

        $this->assertTrue($varian->adjustStock(10, 'initial'));
        $this->assertTrue($varian->adjustStock(-4, 'order'));
        $this->assertSame(6, $varian->fresh()->stock);

        // Stok tidak cukup: ditolak dan stok tidak berubah.
        $this->assertFalse($varian->adjustStock(-7, 'order'));
        $this->assertSame(6, $varian->fresh()->stock);

        $this->assertSame(2, StockMovement::where('variant_id', $varian->id)->count());

        $terakhir = StockMovement::latest('id')->first();
        $this->assertSame(-4, $terakhir->quantity_change);
        $this->assertSame(6, $terakhir->stock_after);
        $this->assertSame('order', $terakhir->reason);
    }

    public function test_rentang_harga_dihitung_dari_varian_aktif_dengan_harga_efektif(): void
    {
        $produk = $this->buatProduk();

        $this->buatVarian($produk, ['name' => 'A', 'price' => 100000]);
        $this->buatVarian($produk, ['name' => 'B', 'price' => 90000, 'discount_price' => 70000]);
        $this->buatVarian($produk, ['name' => 'C', 'price' => 5000, 'is_active' => false]);

        $produk->refreshPriceRange();
        $produk = $produk->fresh();

        $this->assertSame(70000, $produk->price_min);
        $this->assertSame(100000, $produk->price_max);
    }

    public function test_hapus_permanen_produk_ikut_menghapus_varian_gambar_dan_riwayat_stok(): void
    {
        $produk = $this->buatProduk();
        $varian = $this->buatVarian($produk);
        $varian->adjustStock(5, 'initial');
        ProductImage::create(['product_id' => $produk->id, 'path' => 'products/a.jpg']);

        $produk->forceDelete();

        $this->assertSame(0, ProductVariant::count());
        $this->assertSame(0, ProductImage::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_wishlist_unik_dan_terbaca_dari_dua_arah(): void
    {
        $user   = $this->buatUser();
        $produk = $this->buatProduk();

        Wishlist::create(['user_id' => $user->id, 'product_id' => $produk->id]);

        $this->assertSame(1, $user->wishlistProducts()->count());
        $this->assertSame(1, $produk->favoritedBy()->count());

        $this->expectException(QueryException::class);
        Wishlist::create(['user_id' => $user->id, 'product_id' => $produk->id]);
    }
}
