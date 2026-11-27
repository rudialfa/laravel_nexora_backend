<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;

/** Bantuan bersama untuk tes wishlist dan ikuti toko: toko, produk tayang, dan token seller. */
trait SiapkanSosial
{
    use SiapkanPembayaran;

    protected function aturSosial(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    protected function tokoBaru(): Store
    {
        $this->urut++;
        $n = $this->urut;

        $pemilik = User::create(['name' => "Pemilik {$n}", 'email' => "pemilik{$n}@nexora.test", 'password' => 'Rahasia123']);

        $toko = new Store([
            'name'        => "Toko {$n}",
            'slug'        => "toko-{$n}",
            'phone'       => '628123456789',
            'province'    => 'DI Yogyakarta',
            'city'        => 'Sleman',
            'district'    => 'Depok',
            'postal_code' => '55281',
            'street'      => 'Jl. Rahasia No. 1',
        ]);
        $toko->owner_id = $pemilik->id;
        $toko->save();

        return $toko->fresh();
    }

    /** Opsi: toko, nama, harga, status. */
    protected function produk(array $o = []): Product
    {
        $this->urut++;
        $n = $this->urut;

        $toko     = $o['toko'] ?? $this->tokoBaru();
        $kategori = Category::create(['name' => "Kategori {$n}", 'slug' => "kategori-{$n}"]);

        $p = new Product(['name' => $o['nama'] ?? "Produk {$n}", 'slug' => "produk-{$n}", 'category_id' => $kategori->id]);
        $p->store_id = $toko->id;
        $p->forceFill(['status' => $o['status'] ?? Product::STATUS_ACTIVE, 'published_at' => now()])->save();

        $p->variants()->create(['name' => 'Default', 'price' => $o['harga'] ?? 100000, 'weight_gram' => 200, 'is_active' => true]);
        $p->refreshPriceRange();
        $p->images()->create(['path' => "products/foto-{$n}.jpg", 'sort_order' => 0]);

        return $p->fresh();
    }

    /** Token seller pemilik toko ini. */
    protected function tokenToko(Store $toko): string
    {
        $toko->forceFill(['verified_at' => now()])->save();

        $pemilik = $toko->owner;

        if (! $pemilik->hasRole('seller')) {
            $pemilik->assignRole('seller');
        }

        return $pemilik->createToken('test', ['*'], now()->addDay())->plainTextToken;
    }
}
