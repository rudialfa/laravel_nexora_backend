<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use Database\Seeders\SettingSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Bantuan bersama untuk tes pembayaran: data checkout, Midtrans palsu, dan notifikasi bertanda tangan. */
trait SiapkanPembayaran
{
    protected int $urut = 0;

    protected function kunciServer(): string
    {
        return 'SB-Mid-server-test';
    }

    protected function uuidAsing(): string
    {
        return '00000000-0000-4000-8000-000000000000';
    }

    protected function aturMidtrans(): void
    {
        config([
            'midtrans.server_key'    => $this->kunciServer(),
            'midtrans.base_url'      => 'https://api.sandbox.midtrans.com',
            'midtrans.is_production' => false,
        ]);

        $this->seed(SettingSeeder::class);
        Setting::put('payment.allowed_types', ['bank_transfer', 'qris']);
    }

    protected function kirim(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->json($method, $uri, $data);
    }

    /** @return array{0: User, 1: string} */
    protected function pembeli(string $status = 'active'): array
    {
        $this->urut++;

        $user = User::create([
            'name'     => "Pembeli {$this->urut}",
            'email'    => "pembeli{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);

        if ($status !== 'active') {
            $user->forceFill(['status' => $status])->save();
        }

        return [$user, $user->createToken('test', ['*'], now()->addDay())->plainTextToken];
    }

    /**
     * Checkout menunggu pembayaran: satu pesanan Rp210.000 (barang 200.000, ongkir 20.000, voucher 10.000),
     * biaya layanan 1.000, total 211.000. Stok varian 10 dikurangi 2 (sisa 8) dan voucher berstatus reserved.
     * Opsi: batas (waktu batas bayar).
     */
    protected function checkout(User $user, array $o = []): CheckoutGroup
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

        $kategori = Category::create(['name' => "Kategori {$n}", 'slug' => "kategori-{$n}"]);

        $produk = new Product(['name' => "Produk {$n}", 'slug' => "produk-{$n}", 'category_id' => $kategori->id]);
        $produk->store_id = $toko->id;
        $produk->forceFill(['status' => Product::STATUS_ACTIVE, 'published_at' => now()])->save();

        $varian = $produk->variants()->create(['name' => 'Default', 'price' => 100000, 'weight_gram' => 200, 'is_active' => true]);
        $varian->adjustStock(10, 'initial');
        $varian->adjustStock(-2, 'order');

        $grup = new CheckoutGroup([
            'user_id'            => $user->id,
            'number'             => CheckoutGroup::generateNumber(),
            'subtotal'           => 200000,
            'shipping_total'     => 20000,
            'discount_total'     => 10000,
            'service_fee'        => 1000,
            'grand_total'        => 211000,
            'payment_expires_at' => $o['batas'] ?? now()->addHours(24),
        ]);
        $grup->save();

        $order = new Order([
            'checkout_group_id' => $grup->id,
            'user_id'           => $user->id,
            'store_id'          => $toko->id,
            'number'            => Order::generateNumber($grup, 1),
            'shipping_address'  => ['street' => 'Jl. Contoh No. 1'],
            'courier_company'   => 'jne',
            'courier_type'      => 'reg',
            'total_weight_gram' => 400,
            'subtotal'          => 200000,
            'shipping_cost'     => 20000,
            'discount_total'    => 10000,
            'grand_total'       => 210000,
        ]);
        $order->save();

        $order->items()->create([
            'product_id'   => $produk->id,
            'variant_id'   => $varian->id,
            'product_name' => $produk->name,
            'variant_name' => 'Default',
            'price'        => 100000,
            'unit_price'   => 100000,
            'quantity'     => 2,
            'subtotal'     => 200000,
            'weight_gram'  => 200,
        ]);

        $voucher = new Voucher;
        $voucher->forceFill([
            'code'           => "VCH{$n}",
            'name'           => 'Voucher uji',
            'discount_type'  => 'fixed',
            'discount_value' => 10000,
            'min_purchase'   => 0,
            'per_user_limit' => 1,
            'is_active'      => true,
        ])->save();
        $voucher->fresh()->redeem($user, $grup, 200000);

        return $grup->fresh();
    }

    protected function bayar(CheckoutGroup $grup, string $token, string $metode = 'bank_transfer', ?string $bank = 'bca')
    {
        return $this->kirim('POST', "/api/v1/checkout/{$grup->uuid}/pay", array_filter(['method' => $metode, 'bank' => $bank]), $token);
    }

    /** Midtrans palsu: tagihan VA (bca, bni, bri, permata) dan QRIS berhasil dibuat, dan permintaan kedaluwarsa diterima. */
    protected function palsuMidtrans(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/v2/charge' => function (Request $r) {
                $tipe = $r['payment_type'];
                $id   = $r['transaction_details']['order_id'];
                $uuid = (string) Str::uuid();

                $dasar = [
                    'status_code'        => '201',
                    'status_message'     => 'Success',
                    'transaction_id'     => $uuid,
                    'order_id'           => $id,
                    'merchant_id'        => 'G000000',
                    'gross_amount'       => number_format($r['transaction_details']['gross_amount'], 2, '.', ''),
                    'currency'           => 'IDR',
                    'payment_type'       => $tipe,
                    'transaction_status' => 'pending',
                    'fraud_status'       => 'accept',
                ];

                if ($tipe === 'qris') {
                    return Http::response($dasar + [
                        'qr_string' => '00020101QRSTRING',
                        'acquirer'  => 'gopay',
                        'actions'   => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => "https://api.sandbox.midtrans.com/v2/qris/{$uuid}/qr-code"]],
                    ], 201);
                }

                $bank = $r['bank_transfer']['bank'];

                return Http::response($dasar + ($bank === 'permata'
                    ? ['permata_va_number' => '8778000002332015']
                    : ['va_numbers' => [['bank' => $bank, 'va_number' => '1234567890']]]), 201);
            },
            'api.sandbox.midtrans.com/v2/*/expire' => Http::response(['status_code' => '200', 'transaction_status' => 'expire'], 200),
        ]);
    }

    /** Notifikasi Midtrans bertanda tangan benar. $ubah menimpa kolom sebelum tanda tangan dihitung. */
    protected function notifikasi(string $orderId, int $nominal, string $status, array $ubah = []): array
    {
        $kode = ['settlement' => '200', 'pending' => '201', 'deny' => '202', 'cancel' => '200', 'expire' => '407'][$status] ?? '200';

        $payload = array_merge([
            'order_id'           => $orderId,
            'status_code'        => $kode,
            'gross_amount'       => number_format($nominal, 2, '.', ''),
            'transaction_status' => $status,
            'fraud_status'       => 'accept',
            'payment_type'       => 'bank_transfer',
            'transaction_id'     => (string) Str::uuid(),
        ], $ubah);

        $payload['signature_key'] = hash('sha512', $payload['order_id'] . $payload['status_code'] . $payload['gross_amount'] . $this->kunciServer());

        return $payload;
    }
}
