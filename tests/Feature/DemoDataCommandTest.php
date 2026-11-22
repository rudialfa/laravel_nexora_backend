<?php

namespace Tests\Feature;

use App\Console\Commands\DemoDataCommand;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menguji perintah data contoh untuk uji manual (SQLite memori dan disk palsu).
 */
class DemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private function masuk(string $email): string
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => DemoDataCommand::PASSWORD])
            ->assertOk()
            ->json('data.token');
    }

    public function test_perintah_membuat_data_contoh_yang_siap_dipakai_dan_aman_dijalankan_ulang(): void
    {
        Storage::fake('public');

        $this->artisan('nexora:demo-data')->assertExitCode(0);
        $this->artisan('nexora:demo-data')->assertExitCode(0);   // dijalankan ulang: tidak menggandakan

        $penjual = User::where('email', DemoDataCommand::SELLER_EMAIL)->first();
        $pembeli = User::where('email', DemoDataCommand::BUYER_EMAIL)->first();

        $this->assertSame(2, User::count());
        $this->assertTrue($penjual->hasRole('seller'));
        $this->assertTrue($pembeli->hasRole('customer'));
        $this->assertFalse($pembeli->hasRole('seller'));
        $this->assertNotNull($penjual->email_verified_at);
        $this->assertNotNull($pembeli->email_verified_at);

        $this->assertSame(1, Store::count());
        $this->assertSame(1, Wallet::count());
        $this->assertSame(1, Category::count());
        $this->assertSame(1, Brand::count());
        $this->assertSame(1, Product::count());
        $this->assertSame(2, ProductVariant::count());
        $this->assertSame(1, ProductImage::count());
        $this->assertSame($penjual->id, Store::first()->owner_id);

        $produk = Product::first();
        $this->assertSame(Product::STATUS_ACTIVE, $produk->status);
        $this->assertSame([340000, 350000], [$produk->price_min, $produk->price_max]);
        $this->assertSame(25, (int) ProductVariant::sum('stock'));

        // Produk langsung tampil untuk publik.
        $this->getJson('/api/v1/products/' . DemoDataCommand::PRODUCT_SLUG)
            ->assertOk()
            ->assertJson(['data' => ['name' => 'Sepatu Lari Demo', 'price_min' => 340000, 'price_max' => 350000]]);

        // Pembeli demo bisa memasukkannya ke keranjang; penjual demo tidak (toko sendiri).
        $varianId = ProductVariant::where('sku', 'DEMO-42')->value('uuid');

        $tokenPembeli = $this->masuk(DemoDataCommand::BUYER_EMAIL);
        $this->withToken($tokenPembeli)
            ->postJson('/api/v1/cart/items', ['variant_id' => $varianId, 'quantity' => 2])
            ->assertStatus(201)
            ->assertJson(['data' => ['summary' => ['subtotal' => 700000]]]);

        $tokenPenjual = $this->masuk(DemoDataCommand::SELLER_EMAIL);
        $this->withToken($tokenPenjual)
            ->postJson('/api/v1/cart/items', ['variant_id' => $varianId])
            ->assertStatus(422);
    }

    public function test_perintah_menolak_berjalan_di_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->artisan('nexora:demo-data')->assertExitCode(1);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Product::count());
    }
}
