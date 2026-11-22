<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DemoDataCommand extends Command
{
    public const PASSWORD     = 'Rahasia123';
    public const SELLER_EMAIL = 'demo-seller@nexora.test';
    public const BUYER_EMAIL  = 'demo-buyer@nexora.test';
    public const PRODUCT_SLUG = 'sepatu-lari-demo';

    protected $signature = 'nexora:demo-data';

    protected $description = 'Membuat data contoh (penjual, pembeli, toko, produk tayang) untuk uji manual. Hanya untuk lingkungan lokal.';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Perintah ini hanya untuk lingkungan pengembangan dan menolak berjalan di production.');

            return self::FAILURE;
        }

        (new RoleSeeder)->run();

        $penjual = $this->akun(self::SELLER_EMAIL, 'Demo Penjual', ['customer', 'seller']);
        $pembeli = $this->akun(self::BUYER_EMAIL, 'Demo Pembeli', ['customer']);

        $toko = $this->toko($penjual);

        $kategori = Category::firstOrCreate(['slug' => 'demo-olahraga'], ['name' => 'Demo Olahraga']);
        $brand    = Brand::firstOrCreate(['slug' => 'demo-brand'], ['name' => 'Demo Brand']);

        $produk = $this->produk($toko, $kategori, $brand);

        $this->info('Data contoh siap.');
        $this->line('Penjual : ' . self::SELLER_EMAIL . ' / ' . self::PASSWORD . '  (toko: ' . $toko->name . ')');
        $this->line('Pembeli : ' . $pembeli->email . ' / ' . self::PASSWORD);
        $this->line('Produk  : ' . $produk->slug . ' (' . $produk->variants()->count() . ' varian, ' . $produk->status . ')');

        return self::SUCCESS;
    }

    /** Membuat atau memulihkan akun, memverifikasi emailnya, dan mengembalikan passwordnya ke nilai contoh. */
    private function akun(string $email, string $nama, array $roles): User
    {
        $user = User::withTrashed()->where('email', $email)->first();

        if ($user === null) {
            $user = User::create(['name' => $nama, 'email' => $email, 'password' => self::PASSWORD]);
        } elseif ($user->trashed()) {
            $user->restore();
        }

        $user->forceFill([
            'password'          => self::PASSWORD,
            'status'            => 'active',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        foreach ($roles as $role) {
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }

        return $user;
    }

    private function toko(User $penjual): Store
    {
        $toko = Store::withTrashed()->where('owner_id', $penjual->id)->first();

        if ($toko === null) {
            $toko = new Store([
                'name'        => 'Toko Demo',
                'slug'        => 'toko-demo',
                'description' => 'Toko contoh untuk uji manual.',
                'phone'       => '628123456789',
                'province'    => 'DI Yogyakarta',
                'city'        => 'Sleman',
                'district'    => 'Depok',
                'postal_code' => '55281',
                'street'      => 'Jl. Contoh No. 1',
            ]);
            $toko->owner_id    = $penjual->id;
            $toko->verified_at = now();
            $toko->save();
        } elseif ($toko->trashed()) {
            $toko->restore();
        }

        $toko->forceFill(['status' => 'active', 'is_open' => true])->save();

        Wallet::firstOrCreate(['store_id' => $toko->id]);

        return $toko->fresh();
    }

    private function produk(Store $toko, Category $kategori, Brand $brand): Product
    {
        $produk = Product::withTrashed()->where('slug', self::PRODUCT_SLUG)->first();

        if ($produk === null) {
            $produk = new Product([
                'name'        => 'Sepatu Lari Demo',
                'slug'        => self::PRODUCT_SLUG,
                'description' => 'Sepatu lari contoh untuk uji manual.',
                'category_id' => $kategori->id,
                'brand_id'    => $brand->id,
            ]);
            $produk->store_id = $toko->id;
            $produk->forceFill(['status' => Product::STATUS_ACTIVE, 'published_at' => now()])->save();

            $hitam = $produk->variants()->create([
                'name'        => 'Hitam / 42',
                'sku'         => 'DEMO-42',
                'options'     => ['Warna' => 'Hitam', 'Ukuran' => '42'],
                'price'       => 350000,
                'weight_gram' => 900,
                'length_cm'   => 32,
                'width_cm'    => 20,
                'height_cm'   => 12,
                'sort_order'  => 0,
            ]);
            $hitam->adjustStock(20, 'initial', null, null, 'Stok awal data contoh');

            $putih = $produk->variants()->create([
                'name'           => 'Putih / 43',
                'sku'            => 'DEMO-43',
                'options'        => ['Warna' => 'Putih', 'Ukuran' => '43'],
                'price'          => 380000,
                'discount_price' => 340000,
                'weight_gram'    => 950,
                'length_cm'      => 33,
                'width_cm'       => 21,
                'height_cm'      => 12,
                'sort_order'     => 1,
            ]);
            $putih->adjustStock(5, 'initial', null, null, 'Stok awal data contoh');

            $produk->refreshPriceRange();
        } else {
            if ($produk->trashed()) {
                $produk->restore();
            }

            if ($produk->status !== Product::STATUS_ACTIVE) {
                $produk->forceFill(['status' => Product::STATUS_ACTIVE, 'published_at' => $produk->published_at ?? now()])->save();
            }
        }

        $this->pastikanFoto($produk);

        return $produk->fresh();
    }

    /** Membuat satu foto contoh (gambar sederhana) agar produk memenuhi syarat tayang. */
    private function pastikanFoto(Product $produk): void
    {
        $path = 'products/demo-sepatu.jpg';
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $gambar = imagecreatetruecolor(600, 600);
            imagefill($gambar, 0, 0, imagecolorallocate($gambar, 230, 230, 235));
            imagefilledellipse($gambar, 300, 300, 320, 320, imagecolorallocate($gambar, 60, 90, 200));

            ob_start();
            imagejpeg($gambar, null, 85);

            $disk->put($path, (string) ob_get_clean());
        }

        if (! $produk->images()->exists()) {
            $produk->images()->create(['path' => $path, 'sort_order' => 0]);
        }
    }
}
