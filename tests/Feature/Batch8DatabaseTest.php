<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Models\CheckoutGroup;
use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\EcosystemApp;
use App\Models\HomeSection;
use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductReview;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreBankAccount;
use App\Models\StoreReview;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Wallet;
use Database\Seeders\EcosystemAppSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Menguji Batch 8: konten, pengaturan, dan relasi balikan.
 * Berjalan di SQLite memori, tidak menyentuh MySQL.
 */
class Batch8DatabaseTest extends TestCase
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

    /** Membuat satu item pesanan lengkap (pembeli, toko, produk, pesanan). */
    private function buatItemPesanan(): OrderItem
    {
        $pembeli = $this->buatUser();
        $toko    = $this->buatToko();
        $this->urut++;

        $kategori = Category::create(['name' => 'Fashion', 'slug' => "fashion-{$this->urut}"]);

        $produk = new Product([
            'name' => 'Kaos Polos',
            'slug' => "kaos-{$this->urut}",
            'category_id' => $kategori->id,
        ]);
        $produk->store_id = $toko->id;
        $produk->save();

        $varian = $produk->variants()->create([
            'name' => 'Default',
            'price' => 100000,
            'weight_gram' => 200,
        ]);

        $grup = CheckoutGroup::create([
            'user_id'     => $pembeli->id,
            'number'      => CheckoutGroup::generateNumber(),
            'subtotal'    => 100000,
            'grand_total' => 100000,
        ]);

        $pesanan = Order::create([
            'checkout_group_id' => $grup->id,
            'user_id'           => $pembeli->id,
            'store_id'          => $toko->id,
            'number'            => Order::generateNumber($grup, 1),
            'shipping_address'  => ['city' => 'Sleman'],
            'subtotal'          => 100000,
            'grand_total'       => 100000,
        ]);

        return $pesanan->items()->create([
            'product_id' => $produk->id,
            'variant_id' => $varian->id,
            'product_name' => 'Kaos Polos',
            'variant_name' => 'Default',
            'price' => 100000,
            'unit_price' => 100000,
            'quantity' => 1,
            'subtotal' => 100000,
            'weight_gram' => 200,
        ])->load('order');
    }

    private function buatKomplain(Order $pesanan): Dispute
    {
        $komplain = new Dispute([
            'type' => Dispute::TYPE_REFUND,
            'reason_code' => 'item_damaged',
            'description' => 'Barang rusak.',
            'requested_amount' => 100000,
        ]);
        $komplain->forceFill([
            'order_id' => $pesanan->id,
            'user_id' => $pesanan->user_id,
            'store_id' => $pesanan->store_id,
        ])->save();

        return $komplain->fresh();
    }

    // ------------------------------------------------------------------ KONTEN

    public function test_banner_tayang_hanya_yang_aktif_dalam_masa_tayang_dan_berurutan(): void
    {
        Banner::create(['title' => 'C aktif urutan 2', 'image_path' => 'b/c.jpg', 'sort_order' => 2]);
        Banner::create(['title' => 'A aktif urutan 1', 'image_path' => 'b/a.jpg', 'sort_order' => 1]);
        Banner::create(['title' => 'Nonaktif', 'image_path' => 'b/x.jpg', 'is_active' => false]);
        Banner::create(['title' => 'Belum mulai', 'image_path' => 'b/y.jpg', 'starts_at' => now()->addDay()]);
        Banner::create(['title' => 'Sudah berakhir', 'image_path' => 'b/z.jpg', 'ends_at' => now()->subMinute()]);
        Banner::create([
            'title' => 'Dalam masa tayang',
            'image_path' => 'b/w.jpg',
            'sort_order' => 3,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->assertSame(
            ['A aktif urutan 1', 'C aktif urutan 2', 'Dalam masa tayang'],
            Banner::live()->pluck('title')->all()
        );

        $banner = Banner::first();
        $this->assertSame(Banner::LINK_NONE, $banner->link_type);
        $this->assertSame('home_top', $banner->position);
    }

    public function test_bagian_beranda_aktif_berurutan_dan_kode_unik(): void
    {
        HomeSection::create(['code' => 'new', 'title' => 'Terbaru', 'type' => 'new_products', 'sort_order' => 2, 'config' => ['limit' => 10]]);
        HomeSection::create(['code' => 'featured', 'title' => 'Unggulan', 'type' => 'featured_products', 'sort_order' => 1]);
        HomeSection::create(['code' => 'off', 'title' => 'Mati', 'type' => 'best_sellers', 'is_active' => false]);

        $this->assertSame(['featured', 'new'], HomeSection::active()->pluck('code')->all());
        $this->assertSame(['limit' => 10], HomeSection::where('code', 'new')->first()->config);
        $this->assertContains('new_products', HomeSection::TYPES);

        $this->expectException(QueryException::class);
        HomeSection::create(['code' => 'new', 'title' => 'Ganda', 'type' => 'new_products']);
    }

    public function test_halaman_statis_slug_unik_dan_hanya_yang_terbit_tampil(): void
    {
        Page::create(['slug' => 'tentang-nexora', 'title' => 'Tentang Nexora', 'content' => '<p>Isi</p>']);
        Page::create(['slug' => 'draf', 'title' => 'Draf', 'content' => 'x', 'is_published' => false]);

        $this->assertSame(['tentang-nexora'], Page::published()->pluck('slug')->all());
        $this->assertSame('tentang-nexora', (new Page)->forceFill(['slug' => 'tentang-nexora'])->getRouteKey());

        $this->expectException(QueryException::class);
        Page::create(['slug' => 'tentang-nexora', 'title' => 'Ganda', 'content' => 'y']);
    }

    public function test_aplikasi_ekosistem_default_dan_ketersediaan_link(): void
    {
        $app = EcosystemApp::create(['name' => 'Gudang Pro', 'slug' => 'gudang-pro'])->fresh();

        $this->assertSame(36, strlen($app->uuid));
        $this->assertTrue($app->is_active);
        $this->assertFalse($app->isAvailable());            // belum ada link Play Store

        $app->update(['play_store_url' => 'https://play.google.com/store/apps/details?id=contoh']);
        $this->assertTrue($app->fresh()->isAvailable());

        $app->update(['play_store_url' => '   ']);
        $this->assertFalse($app->fresh()->isAvailable());

        EcosystemApp::create(['name' => 'Mati', 'slug' => 'mati', 'is_active' => false]);
        $this->assertSame(['gudang-pro'], EcosystemApp::visible()->pluck('slug')->all());

        $this->expectException(QueryException::class);
        EcosystemApp::create(['name' => 'Ganda', 'slug' => 'gudang-pro']);
    }

    public function test_seeder_ekosistem_empat_aplikasi_aman_dijalankan_ulang_dan_terpisah_dari_kategori(): void
    {
        $this->seed(EcosystemAppSeeder::class);

        $this->assertSame(
            ['absen-pintar', 'ospay-ppob', 'gudang-pro', 'cipta-usaha'],
            EcosystemApp::visible()->pluck('slug')->all()
        );

        // Link yang nanti diisi tidak tertimpa saat seeder dijalankan lagi.
        EcosystemApp::where('slug', 'ospay-ppob')->first()->update(['play_store_url' => 'https://play.google.com/x']);
        $this->seed(EcosystemAppSeeder::class);

        $this->assertSame(4, EcosystemApp::count());
        $this->assertSame('https://play.google.com/x', EcosystemApp::where('slug', 'ospay-ppob')->value('play_store_url'));

        // Aplikasi ekosistem sama sekali tidak masuk kategori atau produk marketplace.
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Product::count());
    }

    // --------------------------------------------------------------- PENGATURAN

    public function test_pengaturan_bawaan_dari_seeder_terbaca_sesuai_tipenya(): void
    {
        $this->seed(SettingSeeder::class);

        $this->assertSame('Nexora', Setting::getValue('marketplace.name'));
        $this->assertSame(5.0, Setting::getValue('marketplace.commission_rate'));
        $this->assertSame(0, Setting::getValue('marketplace.service_fee'));
        $this->assertSame(24, Setting::getValue('payment.expiry_hours'));
        $this->assertSame(3, Setting::getValue('order.auto_complete_days'));
        $this->assertSame(50000, Setting::getValue('withdrawal.min_amount'));
        $this->assertFalse(Setting::getValue('marketplace.maintenance_mode'));
        $this->assertSame(['bank_transfer'], Setting::getValue('payment.allowed_types'));

        $this->assertSame('cadangan', Setting::getValue('tidak.ada', 'cadangan'));
        $this->assertNull(Setting::getValue('tidak.ada'));
    }

    public function test_mengubah_pengaturan_membuang_cache_dan_mencatat_pengubah(): void
    {
        $this->seed(SettingSeeder::class);
        $admin = $this->buatUser();

        $this->assertSame(24, Setting::getValue('payment.expiry_hours'));   // masuk cache

        Setting::put('payment.expiry_hours', 12, $admin->id);
        Setting::put('marketplace.commission_rate', '7.5', $admin->id);
        Setting::put('marketplace.maintenance_mode', true, $admin->id);
        Setting::put('payment.allowed_types', ['bank_transfer', 'gopay'], $admin->id);
        Setting::put('marketplace.name', 'Nexora Market', $admin->id);

        $this->assertSame(12, Setting::getValue('payment.expiry_hours'));
        $this->assertSame(7.5, Setting::getValue('marketplace.commission_rate'));
        $this->assertTrue(Setting::getValue('marketplace.maintenance_mode'));
        $this->assertSame(['bank_transfer', 'gopay'], Setting::getValue('payment.allowed_types'));
        $this->assertSame('Nexora Market', Setting::getValue('marketplace.name'));

        $baris = Setting::where('name', 'payment.expiry_hours')->first();
        $this->assertSame($admin->id, $baris->updater->id);
    }

    public function test_nilai_pengaturan_yang_tidak_sesuai_tipe_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $this->seed(SettingSeeder::class);

        $salah = [
            ['payment.expiry_hours', 'abc'],
            ['payment.expiry_hours', -1],
            ['payment.expiry_hours', 1.5],
            ['marketplace.commission_rate', 'banyak'],
            ['marketplace.maintenance_mode', 'ya'],
            ['payment.allowed_types', 'bank_transfer'],
            ['marketplace.name', ['bukan', 'teks']],
        ];

        foreach ($salah as [$nama, $nilai]) {
            try {
                Setting::put($nama, $nilai);
                $this->fail("Seharusnya InvalidArgumentException untuk {$nama}.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($nama, $e->getMessage());
            }
        }

        $this->assertSame(24, Setting::getValue('payment.expiry_hours'));
        $this->assertSame(5.0, Setting::getValue('marketplace.commission_rate'));
        $this->assertFalse(Setting::getValue('marketplace.maintenance_mode'));
        $this->assertSame('Nexora', Setting::getValue('marketplace.name'));

        // Pengaturan harus sudah didefinisikan (seeder); put tidak membuat nama baru.
        $this->expectException(ModelNotFoundException::class);
        Setting::put('nama.baru', 1);
    }

    public function test_seeder_pengaturan_tidak_menimpa_perubahan_dan_peta_publik_tidak_membocorkan_yang_internal(): void
    {
        $this->seed(SettingSeeder::class);
        Setting::put('payment.expiry_hours', 6);

        $this->seed(SettingSeeder::class);

        $this->assertSame(6, Setting::getValue('payment.expiry_hours'));
        $this->assertSame(8, Setting::count());

        $publik = Setting::publicMap();

        $this->assertSame(
            ['marketplace.maintenance_mode', 'marketplace.name', 'payment.allowed_types'],
            array_keys($publik)
        );
        $this->assertFalse($publik['marketplace.maintenance_mode']);
        $this->assertSame(['bank_transfer'], $publik['payment.allowed_types']);
        $this->assertArrayNotHasKey('marketplace.commission_rate', $publik);
        $this->assertArrayNotHasKey('withdrawal.min_amount', $publik);
    }

    // --------------------------------------------------------- RELASI BALIKAN

    public function test_relasi_balikan_pengguna_dan_checkout(): void
    {
        $item    = $this->buatItemPesanan();
        $pesanan = $item->order;
        $pembeli = $pesanan->buyer;
        $toko    = $pesanan->store;

        ProductReview::forceCreate([
            'order_item_id' => $item->id,
            'user_id' => $pembeli->id,
            'product_id' => $item->product_id,
            'rating' => 5,
        ]);
        StoreReview::forceCreate([
            'order_id' => $pesanan->id,
            'user_id' => $pembeli->id,
            'store_id' => $toko->id,
            'rating' => 4,
        ]);

        $tanya = new ProductQuestion(['question' => 'Ready?']);
        $tanya->forceFill(['product_id' => $item->product_id, 'user_id' => $pembeli->id])->save();

        Conversation::create(['user_id' => $pembeli->id, 'store_id' => $toko->id]);
        $this->buatKomplain($pesanan);
        NotificationSetting::create(['user_id' => $pembeli->id, 'category' => 'order']);

        $voucher = Voucher::create([
            'code' => 'HEMAT10',
            'name' => 'Hemat',
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]);
        $voucher->redeem($pembeli, $pesanan->checkoutGroup, 100000);

        $pembeli = $pembeli->fresh();

        $this->assertSame(1, $pembeli->orders()->count());
        $this->assertSame(1, $pembeli->checkoutGroups()->count());
        $this->assertSame(1, $pembeli->productReviews()->count());
        $this->assertSame(1, $pembeli->storeReviews()->count());
        $this->assertSame(1, $pembeli->productQuestions()->count());
        $this->assertSame(1, $pembeli->conversations()->count());
        $this->assertSame(1, $pembeli->disputes()->count());
        $this->assertSame(1, $pembeli->notificationSettings()->count());
        $this->assertSame(1, $pembeli->voucherUsages()->count());

        $this->assertSame(1, $pesanan->checkoutGroup->voucherUsages()->count());
    }

    public function test_relasi_balikan_toko(): void
    {
        $item    = $this->buatItemPesanan();
        $pesanan = $item->order;
        $pembeli = $pesanan->buyer;
        $toko    = $pesanan->store;
        $admin   = $this->buatUser();

        $dompet = Wallet::create(['store_id' => $toko->id]);

        $rekening = new StoreBankAccount(['bank_code' => 'bca', 'bank_name' => 'BCA', 'account_name' => 'Budi']);
        $rekening->store_id = $toko->id;
        $rekening->setAccountNumber('1234567890');
        $rekening->save();

        $voucher = Voucher::create([
            'code' => 'TOKO10',
            'name' => 'Voucher Toko',
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]);
        $voucher->forceFill(['store_id' => $toko->id])->save();

        Conversation::create(['user_id' => $pembeli->id, 'store_id' => $toko->id]);
        $this->buatKomplain($pesanan);
        StoreReview::forceCreate([
            'order_id' => $pesanan->id,
            'user_id' => $pembeli->id,
            'store_id' => $toko->id,
            'rating' => 5,
        ]);

        $dompet->adjust(100000, $admin, 'Saldo awal uji');
        $dompet->requestWithdrawal($toko->owner, $rekening->fresh(), 50000);

        $toko = $toko->fresh();

        $this->assertSame($dompet->id, $toko->wallet->id);
        $this->assertSame(1, $toko->bankAccounts()->count());
        $this->assertSame(1, $toko->vouchers()->count());
        $this->assertSame(1, $toko->conversations()->count());
        $this->assertSame(1, $toko->disputes()->count());
        $this->assertSame(1, $toko->reviews()->count());
        $this->assertSame(1, $toko->withdrawals()->count());
        $this->assertSame(1, $toko->orders()->count());
    }

    public function test_relasi_balikan_produk_pesanan_dan_item(): void
    {
        $item    = $this->buatItemPesanan();
        $pesanan = $item->order;
        $produk  = $item->product;
        $pembeli = $pesanan->buyer;

        $ulasan = ProductReview::forceCreate([
            'order_item_id' => $item->id,
            'user_id' => $pembeli->id,
            'product_id' => $produk->id,
            'rating' => 5,
        ]);

        $tanya = new ProductQuestion(['question' => 'Ready warna merah?']);
        $tanya->forceFill(['product_id' => $produk->id, 'user_id' => $pembeli->id])->save();

        $komplain = $this->buatKomplain($pesanan);
        $refund   = Refund::create(['order_id' => $pesanan->id, 'dispute_id' => $komplain->id, 'amount' => 1000]);
        $ulasanToko = StoreReview::forceCreate([
            'order_id' => $pesanan->id,
            'user_id' => $pembeli->id,
            'store_id' => $pesanan->store_id,
            'rating' => 4,
        ]);

        $this->assertSame(1, $produk->reviews()->count());
        $this->assertSame(1, $produk->questions()->count());
        $this->assertSame($item->id, $produk->orderItems()->first()->id);

        $this->assertSame($ulasan->id, $item->fresh()->review->id);

        $pesanan = $pesanan->fresh();
        $this->assertSame($komplain->id, $pesanan->disputes()->first()->id);
        $this->assertSame($refund->id, $pesanan->refunds()->first()->id);
        $this->assertSame($ulasanToko->id, $pesanan->storeReview->id);
    }
}
