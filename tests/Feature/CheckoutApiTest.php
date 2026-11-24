<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\CheckoutService;
use App\Services\ShippingQuoteService;
use App\Services\VoucherService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Menguji checkout lewat HTTP (Biteship dipalsukan, tanpa memakai kuota).
 */
class CheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    private const UUID_ASING = '00000000-0000-4000-8000-000000000000';

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'biteship.key'              => 'test-key',
            'biteship.base_url'         => 'https://api.biteship.com',
            'biteship.default_couriers' => ['jne', 'sicepat', 'anteraja'],
        ]);

        $this->seed(SettingSeeder::class);
        Setting::put('marketplace.service_fee', 1000);
    }

    // ----------------------------------------------------------------- BANTUAN

    private function kirim(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->json($method, $uri, $data);
    }

    /** @return array{0: User, 1: string} */
    private function pembeli(string $status = 'active'): array
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

    /** Opsi: area (string atau null), buka (bool), komisi (angka). */
    private function toko(array $o = []): Store
    {
        $this->urut++;

        $pemilik = User::create([
            'name'     => "Pemilik {$this->urut}",
            'email'    => "pemilik{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);

        $toko = new Store([
            'name'        => "Toko {$this->urut}",
            'slug'        => "toko-{$this->urut}",
            'phone'       => '628123456789',
            'province'    => 'DI Yogyakarta',
            'city'        => 'Sleman',
            'district'    => 'Depok',
            'postal_code' => '55281',
            'street'      => 'Jl. Rahasia No. 1',
        ]);
        $toko->owner_id = $pemilik->id;
        $toko->save();

        $toko->forceFill([
            'biteship_area_id' => array_key_exists('area', $o) ? $o['area'] : 'AREA-ASAL',
            'is_open'          => $o['buka'] ?? true,
            'commission_rate'  => $o['komisi'] ?? null,
        ])->save();

        return $toko->fresh();
    }

    private function alamat(User $user, ?string $area = 'AREA-TUJUAN'): Address
    {
        $alamat = new Address;
        $alamat->forceFill([
            'user_id'          => $user->id,
            'label'            => 'Rumah',
            'recipient_name'   => 'Budi',
            'phone'            => '628123456789',
            'province'         => 'DI Yogyakarta',
            'city'             => 'Sleman',
            'district'         => 'Depok',
            'postal_code'      => '55281',
            'street'           => 'Jl. Contoh No. 1',
            'biteship_area_id' => $area,
        ])->save();

        return $alamat->fresh();
    }

    private function kategori(): Category
    {
        $this->urut++;

        return Category::create(['name' => "Kategori {$this->urut}", 'slug' => "kategori-{$this->urut}"]);
    }

    /** Opsi: harga, diskon, stok. Berat selalu 200 gram. */
    private function varian(Store $toko, array $o = []): ProductVariant
    {
        $this->urut++;

        $produk = new Product([
            'name'        => "Produk {$this->urut}",
            'slug'        => "produk-{$this->urut}",
            'category_id' => $this->kategori()->id,
        ]);
        $produk->store_id = $toko->id;
        $produk->forceFill(['status' => Product::STATUS_ACTIVE, 'published_at' => now()])->save();

        $varian = $produk->variants()->create([
            'name'           => 'Default',
            'price'          => $o['harga'] ?? 100000,
            'discount_price' => $o['diskon'] ?? null,
            'weight_gram'    => 200,
            'is_active'      => true,
        ]);

        $stok = $o['stok'] ?? 10;

        if ($stok > 0) {
            $varian->adjustStock($stok, 'initial');
        }

        $produk->images()->create(['path' => "products/foto-{$this->urut}.jpg", 'sort_order' => 0]);

        return $varian->fresh();
    }

    private function isi(User $user, ProductVariant $varian, int $jumlah = 1, bool $dipilih = true): void
    {
        CartItem::create([
            'user_id'     => $user->id,
            'variant_id'  => $varian->id,
            'quantity'    => $jumlah,
            'is_selected' => $dipilih,
        ]);
    }

    private function voucher(array $o = [], ?Store $toko = null): Voucher
    {
        $this->urut++;

        $v = new Voucher;
        $v->forceFill(array_merge([
            'store_id'       => $toko?->id,
            'code'           => "KODE{$this->urut}",
            'name'           => "Voucher {$this->urut}",
            'discount_type'  => 'fixed',
            'discount_value' => 10000,
            'max_discount'   => null,
            'min_purchase'   => 0,
            'quota'          => null,
            'used_count'     => 0,
            'per_user_limit' => 1,
            'is_active'      => true,
        ], $o))->save();

        return $v->fresh();
    }

    private function palsuTarif(): void
    {
        $dasar = ['description' => 'Layanan', 'duration' => '1 - 2 days', 'service_type' => 'standard', 'shipping_type' => 'parcel'];

        Http::fake(['api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'pricing' => [
            $dasar + ['courier_code' => 'jne', 'courier_name' => 'JNE', 'courier_service_code' => 'reg', 'courier_service_name' => 'Reguler', 'price' => 20000],
            $dasar + ['courier_code' => 'sicepat', 'courier_name' => 'SiCepat', 'courier_service_code' => 'reg', 'courier_service_name' => 'Reguler', 'price' => 24000],
        ]])]);
    }

    /**
     * Pembeli dengan alamat, tiga toko, dan empat item keranjang:
     * a1 (toko A, 2 buah, Rp100.000), a2 (toko A, tidak dipilih), b1 (toko B, Rp50.000 setelah diskon), c1 (toko C, tidak ikut checkout).
     */
    private function skenario(): array
    {
        $this->palsuTarif();

        $a = $this->toko();
        $b = $this->toko(['komisi' => 2.5]);
        $c = $this->toko();
        [$user, $token] = $this->pembeli();
        $alamat = $this->alamat($user);

        $a1 = $this->varian($a, ['harga' => 100000]);
        $a2 = $this->varian($a, ['harga' => 30000]);
        $b1 = $this->varian($b, ['harga' => 60000, 'diskon' => 50000]);
        $c1 = $this->varian($c, ['harga' => 20000]);

        $this->isi($user, $a1, 2);
        $this->isi($user, $a2, 1, false);
        $this->isi($user, $b1, 1);
        $this->isi($user, $c1, 1);

        return compact('user', 'token', 'alamat', 'a', 'b', 'c', 'a1', 'a2', 'b1', 'c1');
    }

    /** Masukan checkout untuk toko A dan B dengan JNE Reguler. */
    private function muat(array $s, array $ubah = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'address_id'      => $s['alamat']->uuid,
            'stores'          => [
                ['store_id' => $s['a']->uuid, 'courier_code' => 'jne', 'service_code' => 'reg'],
                ['store_id' => $s['b']->uuid, 'courier_code' => 'jne', 'service_code' => 'reg'],
            ],
        ], $ubah);
    }

    private function tidakAdaYangBerubah(array $s): void
    {
        $this->assertSame(0, CheckoutGroup::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, VoucherUsage::count());
        $this->assertSame([10, 10], [(int) $s['a1']->fresh()->stock, (int) $s['b1']->fresh()->stock]);
        $this->assertSame(4, CartItem::count());
    }

    // -------------------------------------------------------------------- TES

    public function test_checkout_butuh_login_dan_akun_aktif(): void
    {
        Http::fake();

        $rute = [['POST', '/api/v1/checkout/preview'], ['POST', '/api/v1/checkout'], ['GET', '/api/v1/checkout/' . self::UUID_ASING]];

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $token] = $this->pembeli('suspended');

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, [], $token)->assertStatus(403);
        }

        Http::assertNothingSent();
    }

    public function test_checkout_menolak_masukan_tidak_valid(): void
    {
        Http::fake();
        [, $token] = $this->pembeli();

        $toko = ['store_id' => (string) Str::uuid(), 'courier_code' => 'jne', 'service_code' => 'reg'];
        $ok   = ['idempotency_key' => (string) Str::uuid(), 'address_id' => (string) Str::uuid(), 'stores' => [$toko]];
        $sebelas = array_map(fn() => ['store_id' => (string) Str::uuid(), 'courier_code' => 'jne', 'service_code' => 'reg'], range(1, 11));

        $kasus = [
            [[], ['idempotency_key', 'address_id', 'stores']],
            [array_merge($ok, ['idempotency_key' => 'abc']), ['idempotency_key']],
            [array_merge($ok, ['address_id' => 'abc']), ['address_id']],
            [array_merge($ok, ['stores' => []]), ['stores']],
            [array_merge($ok, ['stores' => $sebelas]), ['stores']],
            [array_merge($ok, ['stores' => [$toko, $toko]]), ['stores.0.store_id']],
            [array_merge($ok, ['stores' => [array_merge($toko, ['store_id' => 'abc'])]]), ['stores.0.store_id']],
            [array_merge($ok, ['stores' => [array_merge($toko, ['courier_code' => 'jne!'])]]), ['stores.0.courier_code']],
            [array_merge($ok, ['stores' => [['store_id' => $toko['store_id'], 'courier_code' => 'jne']]]), ['stores.0.service_code']],
            [array_merge($ok, ['voucher_code' => str_repeat('A', 41)]), ['voucher_code']],
            [array_merge($ok, ['stores' => [array_merge($toko, ['note' => str_repeat('a', 501)])]]), ['stores.0.note']],
        ];

        foreach ($kasus as [$data, $kolom]) {
            Cache::flush();   // penghitung laju dibersihkan: tes ini mengirim lebih dari 10 permintaan
            $this->kirim('POST', '/api/v1/checkout', $data, $token)
                ->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors($kolom);
        }

        Http::assertNothingSent();
    }

    public function test_pratinjau_menghitung_rincian_tanpa_menyimpan_apa_pun(): void
    {
        $s = $this->skenario();

        $this->voucher(['code' => 'PLAT15', 'discount_type' => 'percentage', 'discount_value' => 10, 'max_discount' => 15000]);
        $this->voucher(['code' => 'TOKOA10', 'discount_value' => 10000, 'min_purchase' => 100000], $s['a']);

        $data = $this->muat($s, ['voucher_code' => 'plat15']);
        unset($data['idempotency_key']);
        $data['stores'][0]['voucher_code'] = 'tokoa10';

        $res = $this->kirim('POST', '/api/v1/checkout/preview', $data, $s['token'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Rincian checkout berhasil dihitung.'])
            ->assertJsonPath('data.totals.subtotal', 250000)
            ->assertJsonPath('data.totals.shipping_total', 40000)
            ->assertJsonPath('data.totals.discount_total', 25000)
            ->assertJsonPath('data.totals.service_fee', 1000)
            ->assertJsonPath('data.totals.grand_total', 266000)
            ->assertJsonPath('data.platform_voucher.code', 'PLAT15')
            ->assertJsonPath('data.platform_voucher.discount', 15000)
            ->assertJsonPath('data.stores.0.store.id', $s['a']->uuid)
            ->assertJsonPath('data.stores.0.summary.subtotal', 200000)
            ->assertJsonPath('data.stores.0.summary.quantity', 2)
            ->assertJsonPath('data.stores.0.summary.weight_gram', 400)
            ->assertJsonPath('data.stores.0.shipping.courier_code', 'jne')
            ->assertJsonPath('data.stores.0.shipping.price', 20000)
            ->assertJsonPath('data.stores.0.store_voucher.code', 'TOKOA10')
            ->assertJsonPath('data.stores.0.store_voucher.discount', 10000)
            ->assertJsonPath('data.stores.0.discount_total', 21875)
            ->assertJsonPath('data.stores.0.grand_total', 198125)
            ->assertJsonPath('data.stores.1.summary.subtotal', 50000)
            ->assertJsonPath('data.stores.1.store_voucher', null)
            ->assertJsonPath('data.stores.1.discount_total', 3125)
            ->assertJsonPath('data.stores.1.grand_total', 66875);

        $this->assertStringNotContainsString('test-key', $res->getContent());
        $this->assertStringNotContainsString('AREA-ASAL', $res->getContent());

        // Pratinjau tidak menyimpan, menahan stok, memakai voucher, atau mengosongkan keranjang.
        $this->tidakAdaYangBerubah($s);
        $this->assertSame([0, 0], [Voucher::where('code', 'PLAT15')->value('used_count'), Voucher::where('code', 'TOKOA10')->value('used_count')]);
    }

    public function test_biaya_layanan_mengikuti_pengaturan(): void
    {
        $s    = $this->skenario();
        $data = $this->muat($s);
        unset($data['idempotency_key']);

        $this->kirim('POST', '/api/v1/checkout/preview', $data, $s['token'])
            ->assertOk()->assertJsonPath('data.totals.service_fee', 1000)->assertJsonPath('data.totals.grand_total', 291000);

        Setting::put('marketplace.service_fee', 2500);
        $this->kirim('POST', '/api/v1/checkout/preview', $data, $s['token'])
            ->assertOk()->assertJsonPath('data.totals.service_fee', 2500)->assertJsonPath('data.totals.grand_total', 292500);

        Setting::put('marketplace.service_fee', 0);
        $this->kirim('POST', '/api/v1/checkout/preview', $data, $s['token'])
            ->assertOk()->assertJsonPath('data.totals.service_fee', 0)->assertJsonPath('data.totals.grand_total', 290000);
    }

    public function test_checkout_membuat_grup_dan_pesanan_per_toko_dengan_semua_perhitungan(): void
    {
        $s = $this->skenario();

        $platform = $this->voucher(['code' => 'PLAT15', 'discount_type' => 'percentage', 'discount_value' => 10, 'max_discount' => 15000]);
        $tokoA    = $this->voucher(['code' => 'TOKOA10', 'discount_value' => 10000, 'min_purchase' => 100000], $s['a']);

        $data = $this->muat($s, ['voucher_code' => 'plat15']);
        $data['stores'][0]['voucher_code'] = 'tokoa10';
        $data['stores'][1]['note']         = 'Tolong dibungkus rapi';

        $res = $this->kirim('POST', '/api/v1/checkout', $data, $s['token'])
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Checkout berhasil dibuat.'])
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.subtotal', 250000)
            ->assertJsonPath('data.shipping_total', 40000)
            ->assertJsonPath('data.discount_total', 25000)
            ->assertJsonPath('data.service_fee', 1000)
            ->assertJsonPath('data.grand_total', 266000)
            ->assertJsonCount(2, 'data.orders')
            ->assertJsonPath('data.orders.0.store.id', $s['a']->uuid)
            ->assertJsonPath('data.orders.0.status', 'pending_payment')
            ->assertJsonPath('data.orders.0.subtotal', 200000)
            ->assertJsonPath('data.orders.0.shipping_cost', 20000)
            ->assertJsonPath('data.orders.0.discount_total', 21875)
            ->assertJsonPath('data.orders.0.grand_total', 198125)
            ->assertJsonPath('data.orders.0.courier_company', 'jne')
            ->assertJsonPath('data.orders.0.courier_type', 'reg')
            ->assertJsonPath('data.orders.0.shipping_etd', '1 - 2 days')
            ->assertJsonPath('data.orders.0.total_weight_gram', 400)
            ->assertJsonPath('data.orders.0.items.0.product_name', $s['a1']->product->name)
            ->assertJsonPath('data.orders.0.items.0.unit_price', 100000)
            ->assertJsonPath('data.orders.0.items.0.quantity', 2)
            ->assertJsonPath('data.orders.0.items.0.subtotal', 200000)
            ->assertJsonPath('data.orders.1.store.id', $s['b']->uuid)
            ->assertJsonPath('data.orders.1.subtotal', 50000)
            ->assertJsonPath('data.orders.1.discount_total', 3125)
            ->assertJsonPath('data.orders.1.grand_total', 66875)
            ->assertJsonPath('data.orders.1.buyer_note', 'Tolong dibungkus rapi')
            ->assertJsonPath('data.orders.1.items.0.price', 60000)
            ->assertJsonPath('data.orders.1.items.0.unit_price', 50000);

        $this->assertStringNotContainsString('idempotency_key', $res->getContent());
        $this->assertStringNotContainsString('test-key', $res->getContent());

        // Grup dan pesanan.
        $grup = CheckoutGroup::first();

        $this->assertSame(1, CheckoutGroup::count());
        $this->assertSame($grup->uuid, $res->json('data.id'));
        $this->assertStringStartsWith('NXR-', $grup->number);
        $this->assertSame($data['idempotency_key'], $grup->idempotency_key);
        $this->assertSame([250000, 40000, 25000, 1000, 266000], [
            (int) $grup->subtotal,
            (int) $grup->shipping_total,
            (int) $grup->discount_total,
            (int) $grup->service_fee,
            (int) $grup->grand_total,
        ]);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $grup->payment_expires_at->timestamp, 5);

        $pesanan = Order::orderBy('id')->get();

        $this->assertCount(2, $pesanan);
        $this->assertSame([$grup->number . '-1', $grup->number . '-2'], $pesanan->pluck('number')->all());
        $this->assertSame(['pending_payment', 'pending_payment'], $pesanan->pluck('status')->all());
        $this->assertSame([10000, 1250], $pesanan->pluck('commission_amount')->map(fn($v) => (int) $v)->all());
        $this->assertSame([5.0, 2.5], $pesanan->pluck('commission_rate')->map(fn($v) => (float) $v)->all());
        $this->assertSame([198125, 66875], $pesanan->pluck('grand_total')->map(fn($v) => (int) $v)->all());
        $this->assertSame(265000, (int) $pesanan->sum('grand_total'));

        // Alamat tersimpan sebagai salinan.
        $this->assertSame('Budi', $pesanan[0]->shipping_address['recipient_name']);
        $this->assertSame('Jl. Contoh No. 1', $pesanan[0]->shipping_address['street']);
        $this->assertSame('AREA-TUJUAN', $pesanan[0]->shipping_address['biteship_area_id']);

        // Harga tersimpan sebagai salinan, hanya item terpilih dari toko yang dicantumkan.
        $this->assertSame(2, OrderItem::count());
        $this->assertSame([2, 1], OrderItem::orderBy('id')->pluck('quantity')->map(fn($v) => (int) $v)->all());

        // Stok ditahan dan tercatat.
        $this->assertSame([8, 10, 9, 10], [
            (int) $s['a1']->fresh()->stock,
            (int) $s['a2']->fresh()->stock,
            (int) $s['b1']->fresh()->stock,
            (int) $s['c1']->fresh()->stock,
        ]);
        $this->assertSame(2, StockMovement::where('reason', 'order')->count());

        // Voucher dipakai dengan nilai yang sama dengan tagihan.
        $this->assertSame([1, 1], [(int) $platform->fresh()->used_count, (int) $tokoA->fresh()->used_count]);
        $this->assertSame(2, VoucherUsage::count());
        $this->assertSame(15000, (int) VoucherUsage::where('voucher_id', $platform->id)->value('discount_amount'));
        $this->assertSame(10000, (int) VoucherUsage::where('voucher_id', $tokoA->id)->value('discount_amount'));
        $this->assertSame('reserved', VoucherUsage::where('voucher_id', $platform->id)->value('status'));

        // Hanya item yang dibeli keluar dari keranjang.
        $this->assertEqualsCanonicalizing(
            [$s['a2']->id, $s['c1']->id],
            CartItem::where('user_id', $s['user']->id)->pluck('variant_id')->all()
        );
    }

    public function test_kunci_idempotensi_yang_sama_tidak_membuat_checkout_ganda(): void
    {
        $s    = $this->skenario();
        $data = $this->muat($s);

        $pertama = $this->kirim('POST', '/api/v1/checkout', $data, $s['token'])->assertStatus(201);

        $kedua = $this->kirim('POST', '/api/v1/checkout', $data, $s['token'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Checkout sudah dibuat sebelumnya.'])
            ->assertJsonPath('data.id', $pertama->json('data.id'))
            ->assertJsonPath('data.grand_total', $pertama->json('data.grand_total'));

        $this->assertCount(2, $kedua->json('data.orders'));
        $this->assertSame(1, CheckoutGroup::count());
        $this->assertSame(2, Order::count());
        $this->assertSame(8, (int) $s['a1']->fresh()->stock);   // stok hanya ditahan sekali
        $this->assertSame(2, StockMovement::where('reason', 'order')->count());

        // Ongkir tidak dihitung ulang untuk permintaan ulang.
        Http::assertSentCount(2);

        // Kunci dipakai pengguna lain: tidak bentrok dan tidak membocorkan checkout orang lain.
        [, $tokenLain] = $this->pembeli();
        $this->kirim('POST', '/api/v1/checkout', $data, $tokenLain)->assertStatus(404);
    }

    public function test_satu_toko_gagal_membatalkan_seluruh_checkout(): void
    {
        $s = $this->skenario();
        $b1Id = $s['b1']->id;

        // Stok toko B habis tepat setelah pesanan pertama dibuat (balapan dengan pembeli lain).
        $sudah = false;
        DB::listen(function ($q) use (&$sudah, $b1Id) {
            if (! $sudah && str_contains($q->sql, 'insert into "orders"')) {
                $sudah = true;
                DB::table('product_variants')->where('id', $b1Id)->update(['stock' => 0]);
            }
        });

        $res = $this->kirim('POST', '/api/v1/checkout', $this->muat($s), $s['token'])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['cart']);

        $this->assertTrue($sudah);
        $this->assertStringContainsString($s['b']->name, $res->json('errors.cart.0'));

        // Tidak ada yang tersisa: toko A pun tidak ikut dibuat dan stoknya kembali.
        $this->tidakAdaYangBerubah($s);
        $this->assertSame(0, StockMovement::where('reason', 'order')->count());
    }

    public function test_checkout_ditolak_untuk_toko_libur_alamat_layanan_dan_voucher_yang_tidak_sah(): void
    {
        $s = $this->skenario();

        $platform = $this->voucher(['code' => 'PLAT1']);
        $tokoB    = $this->voucher(['code' => 'TOKOB1', 'min_purchase' => 100000], $s['b']);
        $tokoC    = $this->voucher(['code' => 'TOKOC1'], $s['c']);

        $kirim = fn(array $data) => $this->kirim('POST', '/api/v1/checkout', $data, $s['token']);

        // Toko B libur.
        $s['b']->forceFill(['is_open' => false])->save();
        $res = $kirim($this->muat($s))->assertStatus(422)->assertJsonValidationErrors(['stores.1.store_id']);
        $this->assertSame(ShippingQuoteService::PESAN_TOKO_LIBUR, $res->json('errors')['stores.1.store_id'][0]);
        $s['b']->forceFill(['is_open' => true])->save();

        // Alamat tanpa area.
        Address::query()->whereKey($s['alamat']->id)->update(['biteship_area_id' => null]);
        $res = $kirim($this->muat($s))->assertStatus(422)->assertJsonValidationErrors(['address_id']);
        $this->assertSame(ShippingQuoteService::PESAN_ALAMAT_TANPA_AREA, $res->json('errors.address_id.0'));
        Address::query()->whereKey($s['alamat']->id)->update(['biteship_area_id' => 'AREA-TUJUAN']);

        // Layanan yang dipilih tidak ada di hasil ongkir.
        $data = $this->muat($s);
        $data['stores'][0]['service_code'] = 'xyz';
        $res = $kirim($data)->assertStatus(422)->assertJsonValidationErrors(['stores.0.courier_code']);
        $this->assertSame(CheckoutService::PESAN_LAYANAN_TIDAK_ADA, $res->json('errors')['stores.0.courier_code'][0]);

        // Toko yang tidak punya item terpilih di keranjang.
        $data = $this->muat($s);
        $data['stores'][1]['store_id'] = self::UUID_ASING;
        $res = $kirim($data)->assertStatus(422)->assertJsonValidationErrors(['stores.1.store_id']);
        $this->assertSame(ShippingQuoteService::PESAN_TIDAK_ADA_ITEM, $res->json('errors')['stores.1.store_id'][0]);

        // Voucher platform yang tidak ada.
        $res = $kirim($this->muat($s, ['voucher_code' => 'TIDAKADA']))->assertStatus(422)->assertJsonValidationErrors(['voucher_code']);
        $this->assertSame(VoucherService::PESAN_TIDAK_VALID, $res->json('errors.voucher_code.0'));

        // Voucher toko B di slot toko A, voucher platform di slot toko, dan voucher toko di slot platform.
        $data = $this->muat($s);
        $data['stores'][0]['voucher_code'] = 'TOKOB1';
        $kirim($data)->assertStatus(422)->assertJsonValidationErrors(['stores.0.voucher_code']);

        $data = $this->muat($s);
        $data['stores'][0]['voucher_code'] = 'PLAT1';
        $kirim($data)->assertStatus(422)->assertJsonValidationErrors(['stores.0.voucher_code']);

        $kirim($this->muat($s, ['voucher_code' => 'TOKOB1']))->assertStatus(422)->assertJsonValidationErrors(['voucher_code']);

        // Voucher toko yang tokonya tidak ada di checkout, dan belanja di bawah minimum.
        $data = $this->muat($s);
        $data['stores'][1]['voucher_code'] = 'TOKOC1';
        $kirim($data)->assertStatus(422)->assertJsonValidationErrors(['stores.1.voucher_code']);

        $data = $this->muat($s);
        $data['stores'][1]['voucher_code'] = 'TOKOB1';
        $res = $kirim($data)->assertStatus(422)->assertJsonValidationErrors(['stores.1.voucher_code']);
        $this->assertSame('Minimal belanja Rp100.000 untuk memakai voucher ini.', $res->json('errors')['stores.1.voucher_code'][0]);

        // Alamat milik orang lain dijawab 404.
        Cache::flush();   // permintaan ke-11 pada tes ini: penghitung laju dibersihkan
        [$lain]      = $this->pembeli();
        $alamatLain  = $this->alamat($lain);
        $kirim($this->muat($s, ['address_id' => $alamatLain->uuid]))->assertStatus(404);

        // Tidak satu pun penolakan meninggalkan jejak.
        $this->tidakAdaYangBerubah($s);
        $this->assertSame([0, 0, 0], [
            (int) $platform->fresh()->used_count,
            (int) $tokoB->fresh()->used_count,
            (int) $tokoC->fresh()->used_count,
        ]);
    }

    public function test_pembeli_melihat_checkout_miliknya_sendiri_dengan_sisa_waktu_bayar(): void
    {
        $s = $this->skenario();

        $id = $this->kirim('POST', '/api/v1/checkout', $this->muat($s), $s['token'])->assertStatus(201)->json('data.id');

        $this->kirim('GET', "/api/v1/checkout/{$id}", [], $s['token'])
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.grand_total', 291000)
            ->assertJsonCount(2, 'data.orders');

        $sisa = $this->kirim('GET', "/api/v1/checkout/{$id}", [], $s['token'])->json('data.expires_in_seconds');

        $this->assertGreaterThan(86000, $sisa);
        $this->assertLessThanOrEqual(86400, $sisa);

        // Milik orang lain, tidak ada, atau bukan UUID: 404.
        [, $tokenLain] = $this->pembeli();
        $this->kirim('GET', "/api/v1/checkout/{$id}", [], $tokenLain)->assertStatus(404);
        $this->kirim('GET', '/api/v1/checkout/' . self::UUID_ASING, [], $s['token'])->assertStatus(404);
        $this->kirim('GET', '/api/v1/checkout/abc', [], $s['token'])->assertStatus(404);
    }

    public function test_pembuatan_checkout_dibatasi_sepuluh_per_menit(): void
    {
        [, $token] = $this->pembeli();

        for ($n = 1; $n <= 10; $n++) {
            $this->kirim('POST', '/api/v1/checkout', [], $token)->assertStatus(422);
        }

        $this->kirim('POST', '/api/v1/checkout', [], $token)->assertStatus(429);
    }
}
