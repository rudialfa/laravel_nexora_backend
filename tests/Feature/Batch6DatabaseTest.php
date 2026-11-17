<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CheckoutGroup;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductReview;
use App\Models\ReviewImage;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\StoreStaff;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menguji Batch 6: voucher, ulasan, tanya jawab, dan chat.
 * Berjalan di SQLite memori, tidak menyentuh MySQL.
 */
class Batch6DatabaseTest extends TestCase
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

    private function buatGrup(User $pembeli): CheckoutGroup
    {
        return CheckoutGroup::create([
            'user_id'     => $pembeli->id,
            'number'      => CheckoutGroup::generateNumber(),
            'subtotal'    => 200000,
            'grand_total' => 200000,
        ]);
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

        $grup = $this->buatGrup($pembeli);

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

    private function buatVoucher(array $override = []): Voucher
    {
        $this->urut++;

        return Voucher::create(array_merge([
            'code'           => "HEMAT{$this->urut}",
            'name'           => 'Voucher Hemat',
            'discount_type'  => Voucher::TYPE_PERCENTAGE,
            'discount_value' => 10,
        ], $override))->fresh();
    }

    private function buatPercakapan(?Store $toko = null, ?User $pembeli = null): Conversation
    {
        $toko    ??= $this->buatToko();
        $pembeli ??= $this->buatUser();

        return Conversation::create([
            'user_id'  => $pembeli->id,
            'store_id' => $toko->id,
        ])->fresh();
    }

    // ------------------------------------------------------------------ VOUCHER

    public function test_voucher_kode_disimpan_huruf_besar_dan_unik_global(): void
    {
        $voucher = $this->buatVoucher(['code' => '  hemat10 ']);

        $this->assertSame('HEMAT10', $voucher->code);
        $this->assertSame(36, strlen($voucher->uuid));
        $this->assertSame(0, $voucher->used_count);
        $this->assertSame(1, $voucher->per_user_limit);
        $this->assertTrue($voucher->is_active);

        // store_id tidak bisa diisi massal: voucher dari input pengguna selalu "marketplace".
        $voucher2 = $this->buatVoucher(['store_id' => 999]);
        $this->assertNull($voucher2->store_id);

        $this->expectException(QueryException::class);
        $this->buatVoucher(['code' => 'hemat10']);
    }

    public function test_hitung_diskon_persen_nominal_batas_dan_minimum(): void
    {
        $persen = $this->buatVoucher(['discount_value' => 10]);
        $this->assertSame(20000, $persen->calculateDiscount(200000));

        $persenDibatasi = $this->buatVoucher(['discount_value' => 10, 'max_discount' => 15000]);
        $this->assertSame(15000, $persenDibatasi->calculateDiscount(200000));

        $nominal = $this->buatVoucher(['discount_type' => Voucher::TYPE_FIXED, 'discount_value' => 25000]);
        $this->assertSame(25000, $nominal->calculateDiscount(200000));

        // Potongan tidak boleh melebihi subtotal.
        $this->assertSame(10000, $nominal->calculateDiscount(10000) > 10000 ? 0 : $nominal->calculateDiscount(10000));
        $besar = $this->buatVoucher(['discount_type' => Voucher::TYPE_FIXED, 'discount_value' => 300000]);
        $this->assertSame(200000, $besar->calculateDiscount(200000));

        // Persen di atas 100 dianggap 100, lalu dibatasi subtotal.
        $kelebihan = $this->buatVoucher(['discount_value' => 150]);
        $this->assertSame(1000, $kelebihan->calculateDiscount(1000));

        // Di bawah minimum belanja.
        $minimum = $this->buatVoucher(['min_purchase' => 100000]);
        $this->assertSame(0, $minimum->calculateDiscount(90000));
        $this->assertSame(11000, $minimum->calculateDiscount(110000));

        $this->assertSame(0, $persen->calculateDiscount(0));
    }

    public function test_voucher_hanya_berlaku_dalam_syarat(): void
    {
        $this->assertTrue($this->buatVoucher()->isUsableAt());
        $this->assertFalse($this->buatVoucher(['is_active' => false])->isUsableAt());
        $this->assertFalse($this->buatVoucher(['starts_at' => now()->addDay()])->isUsableAt());
        $this->assertFalse($this->buatVoucher(['ends_at' => now()->subMinute()])->isUsableAt());
        $this->assertTrue($this->buatVoucher(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()])->isUsableAt());

        $habis = $this->buatVoucher(['quota' => 1]);
        $habis->forceFill(['used_count' => 1])->save();
        $this->assertFalse($habis->fresh()->isUsableAt());
    }

    public function test_redeem_mencatat_pemakaian_dan_menaikkan_kuota(): void
    {
        $voucher  = $this->buatVoucher(['quota' => 5]);
        $pembeli  = $this->buatUser();
        $grup     = $this->buatGrup($pembeli);

        $pemakaian = $voucher->redeem($pembeli, $grup, 200000)->fresh();

        $this->assertSame(VoucherUsage::STATUS_RESERVED, $pemakaian->status);
        $this->assertSame(20000, $pemakaian->discount_amount);
        $this->assertSame($voucher->id, $pemakaian->voucher->id);
        $this->assertSame(1, $voucher->fresh()->used_count);

        // Voucher yang sama tidak bisa dipasang dua kali pada checkout yang sama.
        $this->expectException(QueryException::class);
        VoucherUsage::create([
            'voucher_id' => $voucher->id,
            'user_id' => $pembeli->id,
            'checkout_group_id' => $grup->id,
            'discount_amount' => 1000,
        ]);
    }

    public function test_redeem_ditolak_jika_batas_per_user_tercapai(): void
    {
        $voucher = $this->buatVoucher(['per_user_limit' => 1]);
        $pembeli = $this->buatUser();

        $voucher->redeem($pembeli, $this->buatGrup($pembeli), 200000);

        try {
            $voucher->redeem($pembeli, $this->buatGrup($pembeli), 200000);
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Batas penggunaan', $e->getMessage());
        }

        $this->assertSame(1, $voucher->fresh()->used_count);

        // Pengguna lain tetap boleh.
        $lain = $this->buatUser();
        $voucher->redeem($lain, $this->buatGrup($lain), 200000);
        $this->assertSame(2, $voucher->fresh()->used_count);
    }

    public function test_redeem_ditolak_jika_kuota_habis(): void
    {
        $voucher = $this->buatVoucher(['quota' => 1]);

        $a = $this->buatUser();
        $voucher->redeem($a, $this->buatGrup($a), 200000);

        $b = $this->buatUser();
        $this->expectException(DomainException::class);
        $voucher->redeem($b, $this->buatGrup($b), 200000);
    }

    public function test_redeem_ditolak_jika_di_bawah_minimum_pembelian(): void
    {
        $voucher = $this->buatVoucher(['min_purchase' => 150000]);
        $pembeli = $this->buatUser();

        try {
            $voucher->redeem($pembeli, $this->buatGrup($pembeli), 100000);
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('syarat', $e->getMessage());
        }

        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertSame(0, VoucherUsage::count());
    }

    public function test_release_mengembalikan_kuota_sekali_saja(): void
    {
        $voucher   = $this->buatVoucher(['quota' => 1]);
        $pembeli   = $this->buatUser();
        $pemakaian = $voucher->redeem($pembeli, $this->buatGrup($pembeli), 200000);

        $this->assertTrue($pemakaian->release());
        $this->assertSame(VoucherUsage::STATUS_RELEASED, $pemakaian->status);
        $this->assertSame(0, $voucher->fresh()->used_count);

        // Dipanggil lagi (misalnya scheduler berjalan dua kali): tidak mengurangi dua kali.
        $this->assertFalse($pemakaian->release());
        $this->assertSame(0, $voucher->fresh()->used_count);

        // Yang sudah "used" tidak bisa dilepas.
        $lain       = $this->buatUser();
        $terpakai   = $voucher->redeem($lain, $this->buatGrup($lain), 200000);
        $this->assertTrue($terpakai->markUsed());
        $this->assertFalse($terpakai->release());
        $this->assertSame(1, $voucher->fresh()->used_count);

        // Pengguna yang pemakaiannya dilepas boleh memakai lagi.
        $voucher2 = $this->buatVoucher(['per_user_limit' => 1]);
        $p        = $this->buatUser();
        $voucher2->redeem($p, $this->buatGrup($p), 200000)->release();
        $voucher2->redeem($p, $this->buatGrup($p), 200000);
        $this->assertSame(1, $voucher2->fresh()->used_count);
    }

    // -------------------------------------------------------------- ULASAN / QA

    public function test_ulasan_produk_unik_per_item_pesanan_dan_default(): void
    {
        $item = $this->buatItemPesanan();

        // Mass assignment hanya menerima isi ulasan.
        $coba = new ProductReview(['rating' => 5, 'status' => 'hidden', 'user_id' => 999]);
        $this->assertNull($coba->status);
        $this->assertNull($coba->user_id);

        $ulasan = ProductReview::forceCreate([
            'order_item_id' => $item->id,
            'user_id'       => $item->order->user_id,
            'product_id'    => $item->product_id,
            'rating'        => 5,
            'comment'       => 'Bahan bagus',
        ])->fresh();

        $this->assertSame(36, strlen($ulasan->uuid));
        $this->assertSame(ProductReview::STATUS_PUBLISHED, $ulasan->status);
        $this->assertFalse($ulasan->is_anonymous);
        $this->assertNull($ulasan->seller_reply);
        $this->assertSame($item->id, $ulasan->orderItem->id);
        $this->assertSame($item->product_id, $ulasan->product->id);
        $this->assertSame($item->order->user_id, $ulasan->user->id);

        $this->expectException(QueryException::class);
        ProductReview::forceCreate([
            'order_item_id' => $item->id,
            'user_id'       => $item->order->user_id,
            'product_id'    => $item->product_id,
            'rating'        => 4,
        ]);
    }

    public function test_gambar_ulasan_ikut_terhapus_bersama_ulasan(): void
    {
        $item = $this->buatItemPesanan();

        $ulasan = ProductReview::forceCreate([
            'order_item_id' => $item->id,
            'user_id'       => $item->order->user_id,
            'product_id'    => $item->product_id,
            'rating'        => 5,
        ]);

        $ulasan->images()->create(['path' => 'reviews/a.jpg', 'sort_order' => 1]);
        $ulasan->images()->create(['path' => 'reviews/b.jpg', 'sort_order' => 0]);

        $this->assertSame(['reviews/b.jpg', 'reviews/a.jpg'], $ulasan->images()->pluck('path')->all());

        $ulasan->delete();

        $this->assertSame(0, ReviewImage::count());
    }

    public function test_ulasan_toko_unik_per_pesanan(): void
    {
        $pesanan = $this->buatItemPesanan()->order;

        $ulasan = StoreReview::forceCreate([
            'order_id' => $pesanan->id,
            'user_id'  => $pesanan->user_id,
            'store_id' => $pesanan->store_id,
            'rating'   => 4,
            'comment'  => 'Pengemasan rapi',
        ])->fresh();

        $this->assertSame(36, strlen($ulasan->uuid));
        $this->assertSame('published', $ulasan->status);
        $this->assertSame($pesanan->store_id, $ulasan->store->id);

        $this->expectException(QueryException::class);
        StoreReview::forceCreate([
            'order_id' => $pesanan->id,
            'user_id'  => $pesanan->user_id,
            'store_id' => $pesanan->store_id,
            'rating'   => 1,
        ]);
    }

    public function test_pertanyaan_produk_dan_jawaban_seller(): void
    {
        $item    = $this->buatItemPesanan();
        $pembeli = $item->order->buyer;
        $penjual = $item->order->store->owner;

        $tanya = new ProductQuestion(['question' => 'Ready warna merah?']);
        $tanya->forceFill(['product_id' => $item->product_id, 'user_id' => $pembeli->id])->save();
        $tanya = $tanya->fresh();

        $this->assertSame('published', $tanya->status);
        $this->assertNull($tanya->answer);

        $tanya->answer($penjual, 'Ready kak, silakan diorder.');
        $tanya = $tanya->fresh();

        $this->assertSame('Ready kak, silakan diorder.', $tanya->answer);
        $this->assertSame($penjual->id, $tanya->answerer->id);
        $this->assertNotNull($tanya->answered_at);
        $this->assertSame($item->product_id, $tanya->product->id);
        $this->assertSame($pembeli->id, $tanya->user->id);
    }

    // --------------------------------------------------------------------- CHAT

    public function test_percakapan_unik_per_pembeli_dan_toko(): void
    {
        $toko    = $this->buatToko();
        $pembeli = $this->buatUser();

        $percakapan = $this->buatPercakapan($toko, $pembeli);

        $this->assertSame(36, strlen($percakapan->uuid));
        $this->assertSame(0, $percakapan->buyer_unread_count);
        $this->assertNull($percakapan->last_message_at);

        $this->expectException(QueryException::class);
        $this->buatPercakapan($toko, $pembeli);
    }

    public function test_sisi_peserta_percakapan(): void
    {
        $toko       = $this->buatToko();
        $pemilik    = $toko->owner;
        $pembeli    = $this->buatUser();
        $percakapan = $this->buatPercakapan($toko, $pembeli);

        $this->assertSame('buyer', $percakapan->sideOf($pembeli));
        $this->assertSame('store', $percakapan->sideOf($pemilik));

        $staffBerizin = $this->buatUser();
        StoreStaff::create([
            'store_id' => $toko->id,
            'user_id' => $staffBerizin->id,
            'permissions' => ['chat.reply'],
            'status' => 'active',
        ]);
        $this->assertSame('store', $percakapan->sideOf($staffBerizin));

        $staffTanpaIzin = $this->buatUser();
        StoreStaff::create([
            'store_id' => $toko->id,
            'user_id' => $staffTanpaIzin->id,
            'permissions' => ['product.manage'],
            'status' => 'active',
        ]);
        $this->assertNull($percakapan->sideOf($staffTanpaIzin));

        $staffDicabut = $this->buatUser();
        StoreStaff::create([
            'store_id' => $toko->id,
            'user_id' => $staffDicabut->id,
            'permissions' => ['chat.reply'],
            'status' => 'revoked',
        ]);
        $this->assertNull($percakapan->sideOf($staffDicabut));

        // Staff dari toko lain tidak punya akses ke toko ini.
        $tokoLain  = $this->buatToko();
        $staffLain = $this->buatUser();
        StoreStaff::create([
            'store_id' => $tokoLain->id,
            'user_id' => $staffLain->id,
            'permissions' => ['chat.reply'],
            'status' => 'active',
        ]);
        $this->assertNull($percakapan->sideOf($staffLain));

        $this->assertNull($percakapan->sideOf($this->buatUser()));
    }

    public function test_kirim_pesan_menaikkan_unread_lawan_dan_last_message(): void
    {
        $toko       = $this->buatToko();
        $pembeli    = $this->buatUser();
        $percakapan = $this->buatPercakapan($toko, $pembeli);

        $pesan = $percakapan->postMessage($pembeli, 'Halo, barangnya ready?');

        $this->assertSame('buyer', $pesan->sender_side);
        $this->assertSame('text', $pesan->fresh()->type);
        $this->assertNull($pesan->fresh()->read_at);
        $this->assertSame(36, strlen($pesan->fresh()->uuid));

        $percakapan = $percakapan->fresh();
        $this->assertSame(1, $percakapan->store_unread_count);
        $this->assertSame(0, $percakapan->buyer_unread_count);
        $this->assertNotNull($percakapan->last_message_at);

        $percakapan->postMessage($toko->owner, 'Ready kak');

        $percakapan = $percakapan->fresh();
        $this->assertSame(1, $percakapan->store_unread_count);
        $this->assertSame(1, $percakapan->buyer_unread_count);
        $this->assertSame(2, $percakapan->messages()->count());
    }

    public function test_orang_asing_dan_pesan_kosong_ditolak(): void
    {
        $percakapan = $this->buatPercakapan();
        $asing      = $this->buatUser();

        try {
            $percakapan->postMessage($asing, 'Halo');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('peserta', $e->getMessage());
        }

        try {
            $percakapan->postMessage($percakapan->buyer, '   ');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('kosong', $e->getMessage());
        }

        try {
            $percakapan->markReadBy($asing);
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('peserta', $e->getMessage());
        }

        $this->assertSame(0, Message::count());
        $this->assertSame(0, $percakapan->fresh()->store_unread_count);
    }

    public function test_pesan_gambar_dengan_lampiran(): void
    {
        $percakapan = $this->buatPercakapan();

        $pesan = $percakapan->postMessage($percakapan->buyer, null, [
            ['path' => 'chat/foto.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 20480],
        ])->fresh();

        $this->assertSame('image', $pesan->type);
        $this->assertNull($pesan->body);
        $this->assertSame(1, $pesan->attachments()->count());
        $this->assertSame('image/jpeg', $pesan->attachments()->first()->mime_type);
        $this->assertSame(1, $percakapan->fresh()->store_unread_count);
    }

    public function test_tandai_dibaca_hanya_pesan_lawan(): void
    {
        $toko       = $this->buatToko();
        $pembeli    = $this->buatUser();
        $percakapan = $this->buatPercakapan($toko, $pembeli);

        $percakapan->postMessage($pembeli, 'a');
        $percakapan->postMessage($pembeli, 'b');
        $percakapan->postMessage($toko->owner, 'c');

        $percakapan = $percakapan->fresh();
        $this->assertSame(2, $percakapan->store_unread_count);
        $this->assertSame(1, $percakapan->buyer_unread_count);

        $jumlah = $percakapan->markReadBy($toko->owner);

        $this->assertSame(2, $jumlah);

        $percakapan = $percakapan->fresh();
        $this->assertSame(0, $percakapan->store_unread_count);
        $this->assertSame(1, $percakapan->buyer_unread_count);   // milik pembeli tidak berubah

        $this->assertSame(0, Message::where('sender_side', 'buyer')->whereNull('read_at')->count());
        $this->assertSame(1, Message::where('sender_side', 'store')->whereNull('read_at')->count());
    }
}
