<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menguji pengecekan kode voucher oleh pembeli terhadap isi keranjang.
 */
class VoucherCheckApiTest extends TestCase
{
    use RefreshDatabase;

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // ----------------------------------------------------------------- BANTUAN

    private function cek(array $data, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->postJson('/api/v1/vouchers/validate', $data);
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

    private function toko(): Store
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

        return $toko->fresh();
    }

    private function kategori(): Category
    {
        $this->urut++;

        return Category::create(['name' => "Kategori {$this->urut}", 'slug' => "kategori-{$this->urut}"]);
    }

    /** Opsi: harga, stok. */
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
            'name'        => 'Default',
            'price'       => $o['harga'] ?? 100000,
            'weight_gram' => 200,
            'is_active'   => true,
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
            'discount_type'  => 'percentage',
            'discount_value' => 10,
            'max_discount'   => null,
            'min_purchase'   => 0,
            'quota'          => null,
            'used_count'     => 0,
            'per_user_limit' => 1,
            'starts_at'      => null,
            'ends_at'        => null,
            'is_active'      => true,
        ], $o))->save();

        return $v->fresh();
    }

    /** Mencatat pemakaian voucher tanpa membuat checkout (kunci asing ditunda, khusus SQLite uji). */
    private function pakai(Voucher $v, User $user, string $status = 'used'): void
    {
        $this->urut++;

        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('voucher_usages')->insert([
            'voucher_id'        => $v->id,
            'user_id'           => $user->id,
            'checkout_group_id' => 1000 + $this->urut,
            'discount_amount'   => 10000,
            'status'            => $status,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /** Satu pembeli dengan satu item terpilih Rp200.000 di satu toko. @return array{0: User, 1: string, 2: Store} */
    private function siap(): array
    {
        [$user, $token] = $this->pembeli();
        $toko = $this->toko();
        $this->isi($user, $this->varian($toko, ['harga' => 100000]), 2);

        return [$user, $token, $toko];
    }

    // -------------------------------------------------------------------- TES

    public function test_cek_voucher_butuh_login_dan_akun_aktif(): void
    {
        $this->cek(['code' => 'ABCD'])->assertStatus(401);

        [, $token] = $this->pembeli('suspended');
        $this->cek(['code' => 'ABCD'], $token)->assertStatus(403);
    }

    public function test_cek_voucher_menolak_masukan_tidak_valid(): void
    {
        [, $token] = $this->pembeli();

        foreach ([[], ['code' => ''], ['code' => str_repeat('A', 41)], ['code' => ['x']]] as $data) {
            $this->cek($data, $token)->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors(['code']);
        }
    }

    public function test_voucher_platform_dihitung_dari_seluruh_item_terpilih_dan_dibatasi_maksimal(): void
    {
        [$user, $token] = $this->pembeli();
        $a = $this->toko();
        $b = $this->toko();

        $this->isi($user, $this->varian($a, ['harga' => 100000]), 2);          // 200.000
        $this->isi($user, $this->varian($b, ['harga' => 50000]), 1);           // 50.000
        $this->isi($user, $this->varian($a, ['harga' => 100000]), 1, false);   // tidak dipilih
        $this->isi($user, $this->varian($b, ['stok' => 0]));                   // stok habis

        $this->voucher(['code' => 'HEMAT10', 'name' => 'Hemat 10', 'discount_value' => 10, 'max_discount' => 20000, 'min_purchase' => 100000]);

        $this->cek(['code' => ' hemat10 '], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Voucher dapat digunakan.'])
            ->assertJsonPath('data.eligible_subtotal', 250000)
            ->assertJsonPath('data.discount_amount', 20000)
            ->assertJsonPath('data.voucher.code', 'HEMAT10')
            ->assertJsonPath('data.voucher.name', 'Hemat 10')
            ->assertJsonPath('data.voucher.discount_type', 'percentage')
            ->assertJsonPath('data.voucher.scope', 'platform')
            ->assertJsonPath('data.voucher.store', null)
            ->assertJsonMissingPath('data.voucher.used_count')
            ->assertJsonMissingPath('data.voucher.quota');

        // Persen tanpa batas potongan.
        $this->voucher(['code' => 'LIMA', 'discount_value' => 5]);
        $this->cek(['code' => 'LIMA'], $token)->assertOk()->assertJsonPath('data.discount_amount', 12500);

        // Nominal tetap yang lebih besar dari belanja: potongan tidak melebihi subtotal.
        $this->voucher(['code' => 'BESAR', 'discount_type' => 'fixed', 'discount_value' => 500000]);
        $this->cek(['code' => 'BESAR'], $token)->assertOk()->assertJsonPath('data.discount_amount', 250000);
    }

    public function test_voucher_toko_hanya_menghitung_item_toko_itu(): void
    {
        [$user, $token] = $this->pembeli();
        $a = $this->toko();
        $b = $this->toko();
        $c = $this->toko();

        $this->isi($user, $this->varian($a, ['harga' => 100000]), 2);   // toko A: 200.000
        $this->isi($user, $this->varian($b, ['harga' => 50000]), 1);    // toko B: 50.000

        $this->voucher(['code' => 'TOKOA', 'discount_type' => 'fixed', 'discount_value' => 15000, 'min_purchase' => 100000], $a);
        $this->voucher(['code' => 'TOKOB', 'discount_type' => 'fixed', 'discount_value' => 5000, 'min_purchase' => 100000], $b);
        $this->voucher(['code' => 'TOKOC', 'discount_type' => 'fixed', 'discount_value' => 5000], $c);

        $this->cek(['code' => 'TOKOA'], $token)
            ->assertOk()
            ->assertJsonPath('data.eligible_subtotal', 200000)
            ->assertJsonPath('data.discount_amount', 15000)
            ->assertJsonPath('data.voucher.scope', 'store')
            ->assertJsonPath('data.voucher.store.id', $a->uuid)
            ->assertJsonPath('data.voucher.store.name', $a->name);

        // Toko tanpa item di keranjang.
        $this->cek(['code' => 'TOKOC'], $token)
            ->assertStatus(422)->assertJsonPath('errors.code.0', VoucherService::PESAN_TOKO_TIDAK_COCOK);

        // Belanja di toko itu di bawah minimum.
        $this->cek(['code' => 'TOKOB'], $token)
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'Minimal belanja Rp100.000 untuk memakai voucher ini.');

        // Item toko A tidak dipilih: sama dengan tidak ada item dari toko itu.
        CartItem::query()->where('user_id', $user->id)->update(['is_selected' => false]);

        $this->cek(['code' => 'TOKOA'], $token)
            ->assertStatus(422)->assertJsonPath('errors.code.0', VoucherService::PESAN_TOKO_TIDAK_COCOK);
    }

    public function test_kode_voucher_yang_tidak_bisa_dipakai_dijawab_dengan_pesan_seragam(): void
    {
        [, $token] = $this->siap();

        $this->voucher(['code' => 'MATI', 'is_active' => false]);
        $this->voucher(['code' => 'NANTI', 'starts_at' => now()->addDay()]);
        $this->voucher(['code' => 'LEWAT', 'ends_at' => now()->subDay()]);
        $this->voucher(['code' => 'DIHAPUS'])->delete();

        foreach (['TIDAKADA', 'MATI', 'NANTI', 'LEWAT', 'DIHAPUS'] as $kode) {
            $this->cek(['code' => $kode], $token)
                ->assertStatus(422)
                ->assertJson(['success' => false])
                ->assertJsonPath('errors.code.0', VoucherService::PESAN_TIDAK_VALID);
        }
    }

    public function test_kuota_habis_dan_batas_pemakaian_per_pengguna(): void
    {
        [$user, $token] = $this->siap();
        [$lain]         = $this->pembeli();

        $this->voucher(['code' => 'HABIS', 'quota' => 1, 'used_count' => 1]);

        $this->cek(['code' => 'HABIS'], $token)
            ->assertStatus(422)->assertJsonPath('errors.code.0', VoucherService::PESAN_KUOTA_HABIS);

        // Batas satu kali: pemakaian yang sudah dilepas atau milik orang lain tidak menghalangi.
        $sekali = $this->voucher(['code' => 'SEKALI', 'discount_type' => 'fixed', 'discount_value' => 1000, 'per_user_limit' => 1]);

        $this->pakai($sekali, $user, 'released');
        $this->pakai($sekali, $lain, 'used');
        $this->cek(['code' => 'SEKALI'], $token)->assertOk();

        $this->pakai($sekali, $user, 'reserved');
        $this->cek(['code' => 'SEKALI'], $token)
            ->assertStatus(422)->assertJsonPath('errors.code.0', VoucherService::PESAN_BATAS_PENGGUNA);

        // Batas dua kali: pemakaian pertama masih boleh, yang kedua tidak.
        $dua = $this->voucher(['code' => 'DUA', 'discount_type' => 'fixed', 'discount_value' => 1000, 'per_user_limit' => 2]);

        $this->pakai($dua, $user, 'used');
        $this->cek(['code' => 'DUA'], $token)->assertOk();

        $this->pakai($dua, $user, 'used');
        $this->cek(['code' => 'DUA'], $token)
            ->assertStatus(422)->assertJsonPath('errors.code.0', VoucherService::PESAN_BATAS_PENGGUNA);
    }

    public function test_voucher_platform_ditolak_jika_tidak_ada_item_terpilih(): void
    {
        [$user, $token] = $this->pembeli();
        $this->voucher(['code' => 'PLATFORM1']);

        // Keranjang kosong.
        $this->cek(['code' => 'PLATFORM1'], $token)
            ->assertStatus(422)->assertJsonPath('errors.code.0', VoucherService::PESAN_KERANJANG_KOSONG);

        // Ada item, tetapi tidak dipilih.
        $this->isi($user, $this->varian($this->toko()), 1, false);

        $this->cek(['code' => 'PLATFORM1'], $token)
            ->assertStatus(422)->assertJsonPath('errors.code.0', VoucherService::PESAN_KERANJANG_KOSONG);
    }

    public function test_cek_voucher_dibatasi_dua_puluh_per_menit(): void
    {
        [, $token] = $this->siap();
        $this->voucher(['code' => 'BEBAS', 'discount_type' => 'fixed', 'discount_value' => 1000, 'per_user_limit' => 5]);

        for ($n = 1; $n <= 20; $n++) {
            $this->cek(['code' => 'BEBAS'], $token)->assertOk();
        }

        $this->cek(['code' => 'BEBAS'], $token)->assertStatus(429);
    }
}
