<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menguji tabel, relasi, dan mesin status Batch 4 (keranjang, checkout, pesanan).
 * Berjalan di SQLite memori, tidak menyentuh MySQL.
 */
class Batch4DatabaseTest extends TestCase
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

    private function buatVarian(Store $toko, int $harga = 100000): ProductVariant
    {
        $this->urut++;

        $kategori = Category::create(['name' => 'Fashion', 'slug' => "fashion-{$this->urut}"]);

        $produk = new Product([
            'name'        => 'Kaos Polos',
            'slug'        => "kaos-{$this->urut}",
            'category_id' => $kategori->id,
        ]);
        $produk->store_id = $toko->id;
        $produk->save();

        return $produk->variants()->create([
            'name' => 'Default',
            'price' => $harga,
            'weight_gram' => 200,
        ]);
    }

    private function buatGrup(User $pembeli, int $total = 105000): CheckoutGroup
    {
        return CheckoutGroup::create([
            'user_id'        => $pembeli->id,
            'number'         => CheckoutGroup::generateNumber(),
            'subtotal'       => $total - 5000,
            'shipping_total' => 5000,
            'grand_total'    => $total,
        ]);
    }

    private function buatPesanan(?User $pembeli = null, ?Store $toko = null, ?CheckoutGroup $grup = null, int $urutan = 1): Order
    {
        $pembeli ??= $this->buatUser();
        $toko    ??= $this->buatToko();
        $grup    ??= $this->buatGrup($pembeli);

        return Order::create([
            'checkout_group_id' => $grup->id,
            'user_id'           => $pembeli->id,
            'store_id'          => $toko->id,
            'number'            => Order::generateNumber($grup, $urutan),
            'shipping_address'  => ['recipient_name' => 'Budi', 'city' => 'Sleman'],
            'courier_company'   => 'jne',
            'courier_type'      => 'reg',
            'subtotal'          => 100000,
            'shipping_cost'     => 5000,
            'grand_total'       => 105000,
            'commission_rate'   => 5,
            'commission_amount' => 5000,
        ])->fresh();
    }

    public function test_item_keranjang_unik_per_user_dan_varian(): void
    {
        $user   = $this->buatUser();
        $varian = $this->buatVarian($this->buatToko());

        $item = $user->cartItems()->create(['variant_id' => $varian->id, 'quantity' => 2]);

        $this->assertTrue($item->fresh()->is_selected);
        $this->assertSame($varian->id, $item->variant->id);

        $this->expectException(QueryException::class);
        $user->cartItems()->create(['variant_id' => $varian->id, 'quantity' => 1]);
    }

    public function test_grup_checkout_punya_default_dan_nomor_unik(): void
    {
        $pembeli = $this->buatUser();
        $grup    = $this->buatGrup($pembeli)->fresh();

        $this->assertSame(36, strlen($grup->uuid));
        $this->assertSame(CheckoutGroup::STATUS_PENDING_PAYMENT, $grup->status);
        $this->assertSame(0, $grup->discount_total);
        $this->assertNull($grup->paid_at);
        $this->assertLessThanOrEqual(40, strlen($grup->number));

        $this->expectException(QueryException::class);
        CheckoutGroup::create([
            'user_id' => $pembeli->id,
            'number' => $grup->number,
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
    }

    public function test_pesanan_punya_default_relasi_dan_status_tidak_bisa_diisi_massal(): void
    {
        $pembeli = $this->buatUser();
        $toko    = $this->buatToko();
        $grup    = $this->buatGrup($pembeli);

        $pesanan = new Order([
            'checkout_group_id' => $grup->id,
            'user_id' => $pembeli->id,
            'store_id' => $toko->id,
            'number' => Order::generateNumber($grup, 1),
            'shipping_address' => ['city' => 'Sleman'],
            'subtotal' => 100000,
            'grand_total' => 100000,
            // Harus diabaikan oleh mass assignment:
            'status'  => 'completed',
            'paid_at' => now(),
        ]);
        $pesanan->save();
        $pesanan = $pesanan->fresh();

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $pesanan->status);
        $this->assertNull($pesanan->paid_at);
        $this->assertSame(36, strlen($pesanan->uuid));
        $this->assertSame($pembeli->id, $pesanan->buyer->id);
        $this->assertSame($toko->id, $pesanan->store->id);
        $this->assertSame($grup->id, $pesanan->checkoutGroup->id);
        $this->assertSame(1, $grup->orders()->count());
        $this->assertSame(1, $toko->orders()->count());
        $this->assertSame(1, $pembeli->orders()->count());
    }

    public function test_alamat_pengiriman_tersimpan_sebagai_json_snapshot(): void
    {
        $pesanan = $this->buatPesanan();

        $this->assertSame('Budi', $pesanan->shipping_address['recipient_name']);
        $this->assertSame('Sleman', $pesanan->shipping_address['city']);
        $this->assertSame('5.00', $pesanan->commission_rate);
    }

    public function test_item_pesanan_menyimpan_snapshot_meski_harga_produk_berubah(): void
    {
        $toko    = $this->buatToko();
        $varian  = $this->buatVarian($toko, 100000);
        $pesanan = $this->buatPesanan(null, $toko);

        $item = $pesanan->items()->create([
            'product_id'   => $varian->product_id,
            'variant_id'   => $varian->id,
            'product_name' => 'Kaos Polos',
            'variant_name' => 'Default',
            'options'      => ['Warna' => 'Merah'],
            'price'        => 100000,
            'unit_price'   => 80000,
            'quantity'     => 2,
            'subtotal'     => 160000,
            'weight_gram'  => 200,
        ]);

        // Penjual menaikkan harga setelah pesanan dibuat.
        $varian->update(['price' => 150000]);

        $item = $item->fresh();
        $this->assertSame(80000, $item->unit_price);
        $this->assertSame(160000, $item->subtotal);
        $this->assertSame(['Warna' => 'Merah'], $item->options);
        $this->assertSame(150000, $varian->fresh()->price);
    }

    public function test_nomor_pesanan_harus_unik(): void
    {
        $pembeli = $this->buatUser();
        $toko    = $this->buatToko();
        $grup    = $this->buatGrup($pembeli);

        $this->buatPesanan($pembeli, $toko, $grup, 1);

        $this->expectException(QueryException::class);
        $this->buatPesanan($pembeli, $toko, $grup, 1);
    }

    public function test_alur_status_normal_dari_bayar_sampai_selesai(): void
    {
        $pesanan = $this->buatPesanan();
        $seller  = $this->buatUser();

        $pesanan->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM, null, 'Midtrans settlement');
        $this->assertNotNull($pesanan->paid_at);

        $pesanan->transitionTo(Order::STATUS_PROCESSING, Order::ACTOR_SELLER, $seller->id);
        $pesanan->transitionTo(Order::STATUS_PACKED, Order::ACTOR_SELLER, $seller->id);
        $pesanan->transitionTo(Order::STATUS_SHIPPED, Order::ACTOR_SELLER, $seller->id);
        $this->assertNotNull($pesanan->shipped_at);

        $pesanan->transitionTo(Order::STATUS_IN_TRANSIT, Order::ACTOR_SYSTEM);
        $pesanan->transitionTo(Order::STATUS_DELIVERED, Order::ACTOR_SYSTEM);
        $this->assertNotNull($pesanan->delivered_at);
        $this->assertTrue(
            $pesanan->auto_complete_at->between(now()->addDays(2)->addHours(23), now()->addDays(3)->addMinute())
        );

        $pesanan->transitionTo(Order::STATUS_COMPLETED, Order::ACTOR_BUYER, $pesanan->user_id);

        $this->assertSame(Order::STATUS_COMPLETED, $pesanan->status);
        $this->assertNotNull($pesanan->completed_at);

        $riwayat = $pesanan->statusHistories;
        $this->assertCount(7, $riwayat);
        $this->assertSame(
            ['pending_payment', 'paid', 'processing', 'packed', 'shipped', 'in_transit', 'delivered'],
            $riwayat->pluck('from_status')->all()
        );
        $this->assertSame('Midtrans settlement', $riwayat->first()->note);
        $this->assertSame('system', $riwayat->first()->actor_type);
        $this->assertSame($seller->id, $riwayat[1]->user_id);
    }

    public function test_perpindahan_status_tidak_valid_ditolak(): void
    {
        $pesanan = $this->buatPesanan();

        try {
            $pesanan->transitionTo(Order::STATUS_SHIPPED, Order::ACTOR_SELLER);
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('pending_payment', $e->getMessage());
        }

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $pesanan->fresh()->status);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_aktor_yang_tidak_berhak_ditolak(): void
    {
        $pesanan = $this->buatPesanan();

        // Pembeli tidak boleh menandai pesanannya sendiri sebagai sudah dibayar.
        try {
            $pesanan->transitionTo(Order::STATUS_PAID, Order::ACTOR_BUYER);
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('buyer', $e->getMessage());
        }
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $pesanan->fresh()->status);

        // Seller tidak boleh menyelesaikan pesanan atas nama pembeli.
        $pesanan->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM);
        $pesanan->transitionTo(Order::STATUS_PROCESSING, Order::ACTOR_SELLER);
        $pesanan->transitionTo(Order::STATUS_PACKED, Order::ACTOR_SELLER);
        $pesanan->transitionTo(Order::STATUS_SHIPPED, Order::ACTOR_SELLER);
        $pesanan->transitionTo(Order::STATUS_DELIVERED, Order::ACTOR_SYSTEM);

        $this->expectException(DomainException::class);
        $pesanan->transitionTo(Order::STATUS_COMPLETED, Order::ACTOR_SELLER);
    }

    public function test_instance_basi_tidak_bisa_melompati_status(): void
    {
        $asli = $this->buatPesanan();
        $basi = Order::find($asli->id);   // salinan kedua dari pesanan yang sama

        $asli->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM);

        // $basi masih mengira statusnya pending_payment, tetapi database sudah "paid".
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $basi->status);

        $this->expectException(DomainException::class);
        $basi->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM);
    }

    public function test_pesanan_batal_lalu_refund(): void
    {
        $pesanan = $this->buatPesanan();
        $pesanan->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM);
        $pesanan->transitionTo(Order::STATUS_CANCELLED, Order::ACTOR_SELLER, null, 'Stok habis');

        $this->assertNotNull($pesanan->cancelled_at);
        $this->assertTrue($pesanan->canTransitionTo(Order::STATUS_REFUNDED, Order::ACTOR_SYSTEM));
        $this->assertFalse($pesanan->canTransitionTo(Order::STATUS_REFUNDED, Order::ACTOR_BUYER));

        $pesanan->transitionTo(Order::STATUS_REFUNDED, Order::ACTOR_SYSTEM);
        $this->assertSame(Order::STATUS_REFUNDED, $pesanan->status);

        // Status akhir: tidak bisa berpindah ke mana pun.
        $this->expectException(DomainException::class);
        $pesanan->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM);
    }

    public function test_produk_yang_pernah_dibeli_tidak_bisa_dihapus_permanen(): void
    {
        $toko    = $this->buatToko();
        $varian  = $this->buatVarian($toko);
        $pesanan = $this->buatPesanan(null, $toko);

        $pesanan->items()->create([
            'product_id' => $varian->product_id,
            'variant_id' => $varian->id,
            'product_name' => 'Kaos Polos',
            'variant_name' => 'Default',
            'price' => 100000,
            'unit_price' => 100000,
            'quantity' => 1,
            'subtotal' => 100000,
            'weight_gram' => 200,
        ]);

        $this->expectException(QueryException::class);
        $varian->product->forceDelete();
    }

    public function test_hapus_permanen_pesanan_menghapus_item_dan_riwayat(): void
    {
        $toko    = $this->buatToko();
        $varian  = $this->buatVarian($toko);
        $pesanan = $this->buatPesanan(null, $toko);

        $pesanan->items()->create([
            'product_id' => $varian->product_id,
            'variant_id' => $varian->id,
            'product_name' => 'Kaos Polos',
            'variant_name' => 'Default',
            'price' => 100000,
            'unit_price' => 100000,
            'quantity' => 1,
            'subtotal' => 100000,
            'weight_gram' => 200,
        ]);
        $pesanan->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM);

        $pesanan->delete();

        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, OrderStatusHistory::count());
    }
}
