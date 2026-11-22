<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\Voucher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menguji pengelolaan voucher oleh admin (platform) dan seller (toko) lewat HTTP.
 */
class VoucherManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private const UUID_ASING = '00000000-0000-4000-8000-000000000000';

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    // ----------------------------------------------------------------- BANTUAN

    private function panggil(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        if ($token !== null) {
            $this->withToken($token);
        }

        return $this->json($method, $uri, $data);
    }

    /** @return array{0: User, 1: string} */
    private function akun(string $peran): array
    {
        $this->urut++;

        $user = User::create([
            'name'     => "Pengguna {$this->urut}",
            'email'    => "pengguna{$this->urut}@nexora.test",
            'password' => 'Rahasia123',
        ]);
        $user->assignRole($peran);

        return [$user, $user->createToken('test', ['*'], now()->addDay())->plainTextToken];
    }

    /** @return array{0: User, 1: string, 2: Store} */
    private function penjual(): array
    {
        [$user, $token] = $this->akun('seller');
        $this->urut++;

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
        $toko->owner_id    = $user->id;
        $toko->verified_at = now();
        $toko->save();

        return [$user, $token, $toko->fresh()];
    }

    /** Membuat voucher langsung di database. */
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

    private function data(array $o = []): array
    {
        return array_merge([
            'code'           => 'HEMAT10',
            'name'           => 'Hemat 10 Persen',
            'discount_type'  => 'percentage',
            'discount_value' => 10,
            'max_discount'   => 50000,
            'min_purchase'   => 100000,
            'quota'          => 100,
            'per_user_limit' => 2,
            'starts_at'      => now()->subDay()->toIso8601String(),
            'ends_at'        => now()->addDays(7)->toIso8601String(),
        ], $o);
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

    private function kode($res): array
    {
        return collect($res->json('data'))->pluck('code')->all();
    }

    // -------------------------------------------------------------------- TES

    public function test_voucher_hanya_untuk_peran_yang_sesuai(): void
    {
        [, $tokenAdmin]   = $this->akun('admin');
        [, $tokenPembeli] = $this->akun('customer');
        [, $tokenPenjual] = $this->penjual();

        $v = $this->voucher();

        $rute = fn(string $awal) => [
            ['GET', $awal],
            ['POST', $awal],
            ['GET', "{$awal}/{$v->uuid}"],
            ['PATCH', "{$awal}/{$v->uuid}"],
            ['DELETE', "{$awal}/{$v->uuid}"],
        ];

        foreach ($rute('/api/v1/admin/vouchers') as [$method, $uri]) {
            $this->panggil($method, $uri)->assertStatus(401);
            $this->panggil($method, $uri, [], $tokenPembeli)->assertStatus(403);
            $this->panggil($method, $uri, [], $tokenPenjual)->assertStatus(403);
        }

        foreach ($rute('/api/v1/seller/vouchers') as [$method, $uri]) {
            $this->panggil($method, $uri)->assertStatus(401);
            $this->panggil($method, $uri, [], $tokenPembeli)->assertStatus(403);
            $this->panggil($method, $uri, [], $tokenAdmin)->assertStatus(403);
        }

        $this->assertSame(1, Voucher::count());
    }

    public function test_admin_membuat_voucher_platform_dengan_kode_huruf_besar_dan_input_sensitif_diabaikan(): void
    {
        [$admin, $token] = $this->akun('admin');

        $res = $this->panggil('POST', '/api/v1/admin/vouchers', $this->data([
            'code' => ' hemat-10 ',
            'store_id' => 999,
            'used_count' => 50,
            'created_by' => 999,
            'uuid' => 'abc',
            'id' => 5,
        ]), $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Voucher berhasil dibuat.'])
            ->assertJsonPath('data.code', 'HEMAT-10')
            ->assertJsonPath('data.scope', 'platform')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.used_count', 0)
            ->assertJsonPath('data.discount_value', 10)
            ->assertJsonPath('data.max_discount', 50000)
            ->assertJsonPath('data.quota', 100)
            ->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.store_id');

        $v = Voucher::where('code', 'HEMAT-10')->first();

        $this->assertSame(1, Voucher::count());
        $this->assertNull($v->store_id);
        $this->assertSame($admin->id, (int) $v->created_by);
        $this->assertSame(0, (int) $v->used_count);
        $this->assertSame($v->uuid, $res->json('data.id'));
        $this->assertNotSame('abc', $v->uuid);
        $this->assertSame(36, strlen($res->json('data.id')));
    }

    public function test_pembuatan_voucher_menolak_masukan_tidak_valid(): void
    {
        [, $token] = $this->akun('admin');
        $this->voucher(['code' => 'DUPLIKAT']);

        $this->panggil('POST', '/api/v1/admin/vouchers', [], $token)
            ->assertStatus(422)->assertJson(['success' => false])
            ->assertJsonValidationErrors(['code', 'name', 'discount_type', 'discount_value']);

        $kasus = [
            [['code' => 'ab'], 'code'],
            [['code' => 'KODE SPASI'], 'code'],
            [['code' => 'KODE!'], 'code'],
            [['code' => 'duplikat'], 'code'],
            [['name' => ''], 'name'],
            [['discount_type' => 'bonus'], 'discount_type'],
            [['discount_value' => 0], 'discount_value'],
            [['discount_value' => 101], 'discount_value'],
            [['discount_value' => 'abc'], 'discount_value'],
            [['discount_type' => 'fixed', 'discount_value' => 5000, 'max_discount' => 1000], 'max_discount'],
            [['min_purchase' => -1], 'min_purchase'],
            [['quota' => 0], 'quota'],
            [['per_user_limit' => 0], 'per_user_limit'],
            [['per_user_limit' => 101], 'per_user_limit'],
            [['starts_at' => null, 'ends_at' => now()->subDay()->toIso8601String()], 'ends_at'],
            [['starts_at' => now()->addDays(5)->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String()], 'ends_at'],
        ];

        foreach ($kasus as [$ubah, $kolom]) {
            $this->panggil('POST', '/api/v1/admin/vouchers', $this->data($ubah), $token)
                ->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors([$kolom]);
        }

        $this->assertSame(1, Voucher::count());
    }

    public function test_seller_membuat_voucher_toko_dan_kode_unik_secara_global(): void
    {
        [$user, $token, $toko] = $this->penjual();
        [, $tokenLain, $tokoLain] = $this->penjual();

        $this->panggil('POST', '/api/v1/seller/vouchers', $this->data(['code' => 'toko-a', 'store_id' => $tokoLain->id, 'created_by' => 999]), $token)
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'TOKO-A')
            ->assertJsonPath('data.scope', 'store');

        $v = Voucher::where('code', 'TOKO-A')->first();

        $this->assertSame($toko->id, (int) $v->store_id);
        $this->assertSame($user->id, (int) $v->created_by);

        // Kode sama dengan voucher toko lain atau voucher platform ditolak.
        $this->voucher(['code' => 'GLOBAL1']);

        $this->panggil('POST', '/api/v1/seller/vouchers', $this->data(['code' => 'global1']), $token)
            ->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->panggil('POST', '/api/v1/seller/vouchers', $this->data(['code' => 'TOKO-A']), $tokenLain)
            ->assertStatus(422)->assertJsonValidationErrors(['code']);

        // Seller mengubah voucher miliknya sendiri.
        $this->panggil('PATCH', "/api/v1/seller/vouchers/{$v->uuid}", ['is_active' => false], $token)
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->assertSame(2, Voucher::count());
    }

    public function test_daftar_voucher_dipisah_per_pemilik_dengan_filter_dan_paginasi(): void
    {
        [, $tokenAdmin] = $this->akun('admin');
        [, $token1, $toko1] = $this->penjual();
        [,, $toko2]        = $this->penjual();

        $this->voucher(['code' => 'PLAT-AKTIF']);
        $this->voucher(['code' => 'PLAT-MATI', 'is_active' => false]);
        $this->voucher(['code' => 'PLAT-LEWAT', 'ends_at' => now()->subDay()]);
        $this->voucher(['code' => 'PLAT-NANTI', 'starts_at' => now()->addDay()]);
        $this->voucher(['code' => 'PLAT-HABIS', 'quota' => 5, 'used_count' => 5]);

        $this->voucher(['code' => 'TOKO1-A'], $toko1);
        $this->voucher(['code' => 'TOKO1-B', 'is_active' => false], $toko1);
        $this->voucher(['code' => 'TOKO2-A'], $toko2);

        // Admin hanya melihat voucher platform.
        $res = $this->panggil('GET', '/api/v1/admin/vouchers?per_page=50', [], $tokenAdmin)->assertOk()->assertJson(['success' => true]);

        $this->assertEqualsCanonicalizing(['PLAT-AKTIF', 'PLAT-MATI', 'PLAT-LEWAT', 'PLAT-NANTI', 'PLAT-HABIS'], $this->kode($res));
        $this->assertSame(5, $res->json('meta.total'));

        // Seller hanya melihat voucher tokonya.
        $res = $this->panggil('GET', '/api/v1/seller/vouchers', [], $token1)->assertOk();

        $this->assertEqualsCanonicalizing(['TOKO1-A', 'TOKO1-B'], $this->kode($res));

        // Filter status.
        $status = ['active' => 'PLAT-AKTIF', 'inactive' => 'PLAT-MATI', 'expired' => 'PLAT-LEWAT', 'scheduled' => 'PLAT-NANTI', 'exhausted' => 'PLAT-HABIS'];

        foreach ($status as $filter => $kode) {
            $res = $this->panggil('GET', "/api/v1/admin/vouchers?status={$filter}", [], $tokenAdmin)->assertOk();

            $this->assertSame([$kode], $this->kode($res), $filter);
            $this->assertSame($filter, $res->json('data.0.status'));
        }

        // Pencarian: huruf kecil cocok, karakter khusus diperlakukan apa adanya.
        $this->assertSame(['PLAT-LEWAT'], $this->kode($this->panggil('GET', '/api/v1/admin/vouchers?q=lewat', [], $tokenAdmin)->assertOk()));
        $this->assertSame(5, count($this->kode($this->panggil('GET', '/api/v1/admin/vouchers?q=PLAT', [], $tokenAdmin)->assertOk())));
        $this->assertSame([], $this->kode($this->panggil('GET', '/api/v1/admin/vouchers?q=' . urlencode('%'), [], $tokenAdmin)->assertOk()));
        $this->assertSame([], $this->kode($this->panggil('GET', '/api/v1/admin/vouchers?q=' . urlencode('_'), [], $tokenAdmin)->assertOk()));

        // Paginasi tidak menduplikasi atau melewatkan baris.
        $semua = [];

        foreach ([1, 2, 3] as $hal) {
            $res = $this->panggil('GET', "/api/v1/admin/vouchers?per_page=2&page={$hal}", [], $tokenAdmin)->assertOk();

            $this->assertSame([5, 3, 2], [$res->json('meta.total'), $res->json('meta.last_page'), $res->json('meta.per_page')]);
            $semua = array_merge($semua, $this->kode($res));
        }

        $this->assertCount(5, array_unique($semua));
        $this->assertCount(1, $this->kode($this->panggil('GET', '/api/v1/admin/vouchers?per_page=2&page=3', [], $tokenAdmin)));

        foreach (['?per_page=100', '?per_page=0', '?status=abc', '?q=' . str_repeat('a', 101), '?page=0'] as $salah) {
            $this->panggil('GET', "/api/v1/admin/vouchers{$salah}", [], $tokenAdmin)->assertStatus(422);
        }
    }

    public function test_voucher_milik_pihak_lain_dijawab_404(): void
    {
        [, $tokenAdmin] = $this->akun('admin');
        [, $token1, $toko1] = $this->penjual();
        [,, $toko2]        = $this->penjual();

        $milik1   = $this->voucher(['code' => 'MILIK1'], $toko1);
        $milik2   = $this->voucher(['code' => 'MILIK2'], $toko2);
        $platform = $this->voucher(['code' => 'PLATFORM1']);

        // Seller menyentuh voucher toko lain dan voucher platform.
        foreach ([$milik2, $platform] as $v) {
            $this->panggil('GET', "/api/v1/seller/vouchers/{$v->uuid}", [], $token1)->assertStatus(404);
            $this->panggil('PATCH', "/api/v1/seller/vouchers/{$v->uuid}", ['name' => 'Diubah'], $token1)->assertStatus(404);
            $this->panggil('DELETE', "/api/v1/seller/vouchers/{$v->uuid}", [], $token1)->assertStatus(404);
        }

        // Admin menyentuh voucher toko.
        $this->panggil('GET', "/api/v1/admin/vouchers/{$milik1->uuid}", [], $tokenAdmin)->assertStatus(404);
        $this->panggil('PATCH', "/api/v1/admin/vouchers/{$milik1->uuid}", ['name' => 'Diubah'], $tokenAdmin)->assertStatus(404);
        $this->panggil('DELETE', "/api/v1/admin/vouchers/{$milik1->uuid}", [], $tokenAdmin)->assertStatus(404);

        // Tidak ada atau bukan UUID.
        $this->panggil('GET', '/api/v1/seller/vouchers/' . self::UUID_ASING, [], $token1)->assertStatus(404);
        $this->panggil('GET', '/api/v1/seller/vouchers/abc', [], $token1)->assertStatus(404);
        $this->panggil('GET', '/api/v1/admin/vouchers/' . self::UUID_ASING, [], $tokenAdmin)->assertStatus(404);

        // Tidak ada yang berubah atau terhapus.
        $this->assertSame(3, Voucher::count());
        $this->assertSame($milik2->name, $milik2->fresh()->name);
        $this->assertSame($milik1->name, $milik1->fresh()->name);
        $this->assertSame($platform->name, $platform->fresh()->name);
    }

    public function test_mengubah_voucher_sebagian_kode_tetap_dan_aturan_dijaga(): void
    {
        [, $token] = $this->akun('admin');

        $v   = $this->voucher(['code' => 'UBAH1', 'quota' => 10, 'used_count' => 5, 'max_discount' => 30000, 'starts_at' => now()->subDay()]);
        $url = "/api/v1/admin/vouchers/{$v->uuid}";

        // Kode, kepemilikan, dan jumlah pemakaian tidak bisa diubah lewat input.
        $this->panggil('PATCH', $url, ['name' => 'Nama Baru', 'is_active' => false, 'code' => 'GANTI', 'used_count' => 0, 'store_id' => 99], $token)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Voucher diperbarui.'])
            ->assertJsonPath('data.name', 'Nama Baru')
            ->assertJsonPath('data.code', 'UBAH1')
            ->assertJsonPath('data.used_count', 5)
            ->assertJsonPath('data.status', 'inactive');

        $this->assertNull($v->fresh()->store_id);

        // Kuota tidak boleh di bawah jumlah pemakaian.
        $this->panggil('PATCH', $url, ['quota' => 3], $token)->assertStatus(422)->assertJsonValidationErrors(['quota']);
        $this->assertSame(10, (int) $v->fresh()->quota);

        // Kuota boleh dikosongkan (tanpa batas), dan voucher diaktifkan lagi.
        $this->panggil('PATCH', $url, ['quota' => null, 'is_active' => true], $token)
            ->assertOk()->assertJsonPath('data.quota', null)->assertJsonPath('data.status', 'active');

        // Beralih ke nominal tetap mengosongkan batas potongan.
        $this->panggil('PATCH', $url, ['discount_type' => 'fixed', 'discount_value' => 20000], $token)
            ->assertOk()
            ->assertJsonPath('data.discount_type', 'fixed')
            ->assertJsonPath('data.discount_value', 20000)
            ->assertJsonPath('data.max_discount', null);

        // Persen tidak boleh lebih dari 100, termasuk saat hanya jenisnya yang diganti.
        $this->panggil('PATCH', $url, ['discount_type' => 'percentage', 'discount_value' => 150], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['discount_value']);
        $this->panggil('PATCH', $url, ['discount_type' => 'percentage'], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['discount_value']);

        // Waktu berakhir harus setelah waktu mulai yang sudah tersimpan.
        $this->panggil('PATCH', $url, ['ends_at' => now()->subDays(2)->toIso8601String()], $token)
            ->assertStatus(422)->assertJsonValidationErrors(['ends_at']);

        // Tanpa isian tidak mengubah apa pun.
        $this->panggil('PATCH', $url, [], $token)->assertOk()->assertJsonPath('data.discount_type', 'fixed');

        $this->assertSame('fixed', $v->fresh()->discount_type);
    }

    public function test_hapus_voucher_hanya_jika_belum_pernah_dipakai(): void
    {
        [, $tokenAdmin] = $this->akun('admin');
        [$pembeli]      = $this->akun('customer');
        [, $tokenPenjual, $toko] = $this->penjual();

        $bersih   = $this->voucher(['code' => 'BERSIH1']);
        $terpakai = $this->voucher(['code' => 'PAKAI1']);
        $milikToko = $this->voucher(['code' => 'TOKOPUS'], $toko);

        $this->pakai($terpakai, $pembeli, 'released');

        // Belum pernah dipakai: terhapus permanen dan kodenya bebas lagi.
        $this->panggil('DELETE', "/api/v1/admin/vouchers/{$bersih->uuid}", [], $tokenAdmin)
            ->assertOk()->assertJson(['success' => true, 'message' => 'Voucher dihapus.']);

        $this->assertNull(Voucher::withTrashed()->where('code', 'BERSIH1')->first());

        $this->panggil('POST', '/api/v1/admin/vouchers', $this->data(['code' => 'bersih1']), $tokenAdmin)->assertStatus(201);

        // Pernah dipakai (walau sudah dilepas): ditolak dan tetap ada.
        $this->panggil('DELETE', "/api/v1/admin/vouchers/{$terpakai->uuid}", [], $tokenAdmin)
            ->assertStatus(422)->assertJsonValidationErrors(['voucher']);

        $this->assertNotNull(Voucher::where('code', 'PAKAI1')->first());

        // Seller menghapus voucher tokonya sendiri.
        $this->panggil('DELETE', "/api/v1/seller/vouchers/{$milikToko->uuid}", [], $tokenPenjual)->assertOk();

        $this->assertNull(Voucher::withTrashed()->where('code', 'TOKOPUS')->first());
    }
}
