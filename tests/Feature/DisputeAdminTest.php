<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\Refund;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SiapkanSengketa;
use Tests\TestCase;

/**
 * Menguji keputusan admin atas komplain: persetujuan, penolakan, dana seller, dan pengembalian dana.
 */
class DisputeAdminTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanSengketa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturSengketa();
    }

    private function buka(string $token, Order $o, array $data = []): string
    {
        return $this->ajukan($o, $token, $data)->assertStatus(201)->json('data.id');
    }

    /** @return array{0: float|int, 1: float|int} saldo tersedia dan tertahan */
    private function saldo($wallet): array
    {
        $w = $wallet->fresh();

        return [(int) $w->balance, (int) $w->pending_balance];
    }

    private function jenis($wallet): array
    {
        return WalletTransaction::where('wallet_id', $wallet->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_endpoint_admin_butuh_login_akun_aktif_dan_peran_admin(): void
    {
        $u = $this->uuidAsing();

        $rute = [
            ['GET', '/api/v1/admin/disputes'],
            ['GET', "/api/v1/admin/disputes/{$u}"],
            ['POST', "/api/v1/admin/disputes/{$u}/review"],
            ['POST', "/api/v1/admin/disputes/{$u}/approve"],
            ['POST', "/api/v1/admin/disputes/{$u}/reject"],
            ['POST', "/api/v1/admin/disputes/{$u}/refund"],
            ['GET', '/api/v1/admin/refunds'],
            ['POST', "/api/v1/admin/refunds/{$u}/complete"],
            ['POST', "/api/v1/admin/refunds/{$u}/fail"],
        ];

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif]    = $this->pembeli('suspended');
        [, $biasa]       = $this->pembeli();
        [,, $o]         = $this->pesananSampai();
        $tokenSeller     = $this->penjual($o);

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
            $this->kirim($method, $uri, [], $biasa)->assertStatus(403);
            $this->kirim($method, $uri, [], $tokenSeller)->assertStatus(403);
        }
    }

    public function test_menyetujui_komplain_penuh_membalik_dana_seller_membuat_refund_dan_menyelesaikannya(): void
    {
        [, $token, $o, $w] = $this->pesananSampai();
        [$admin, $ta]      = $this->akunAdmin();

        $this->assertSame([0, 190000], $this->saldo($w));

        $id = $this->buka($token, $o);

        // Menyetujui langsung dari status "open" memulai peninjauan otomatis.
        $res = $this->kirim('POST', "/api/v1/admin/disputes/{$id}/approve", ['note' => 'Bukti foto jelas'], $ta)
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Komplain disetujui.'])
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_amount', 210000)
            ->assertJsonPath('data.bearer', 'seller')
            ->assertJsonPath('data.seller_charged', 190000)
            ->assertJsonPath('data.shortfall', 0)
            ->assertJsonPath('data.service_fee_refunded', 0)
            ->assertJsonPath('data.admin_note', 'Bukti foto jelas')
            ->assertJsonPath('data.buyer.name', $o->buyer->name)
            ->assertJsonCount(1, 'data.refunds')
            ->assertJsonPath('data.refunds.0.amount', 210000)
            ->assertJsonPath('data.refunds.0.status', 'pending');

        $d = Dispute::first();

        $this->assertSame($admin->id, (int) $d->reviewed_by);
        $this->assertSame('refunded', $o->fresh()->status);

        // Dana seller dibalik dari saldo tertahan: tidak ada yang tersisa untuk dicairkan.
        $this->assertSame([0, 0], $this->saldo($w));
        $this->assertSame(['sale_hold', 'sale_reversal'], $this->jenis($w));
        $this->assertSame(-190000, (int) WalletTransaction::where('type', 'sale_reversal')->value('amount'));

        $refund = Refund::first();

        $this->assertSame([$o->id, $d->id, 210000, 'pending'], [(int) $refund->order_id, (int) $refund->dispute_id, (int) $refund->amount, $refund->status]);

        // Pembeli melihat hasilnya, tetapi tidak melihat rincian internal.
        $this->kirim('GET', "/api/v1/disputes/{$id}", [], $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.admin_note', 'Bukti foto jelas')
            ->assertJsonPath('data.refunds.0.status', 'pending')
            ->assertJsonMissingPath('data.bearer')
            ->assertJsonMissingPath('data.seller_charged');

        // Refund diselesaikan: komplain ikut selesai.
        $rid = $res->json('data.refunds.0.id');
        $url = "/api/v1/admin/refunds/{$rid}/complete";

        $this->kirim('POST', $url, [], $ta)->assertStatus(422)->assertJsonValidationErrors(['reference']);

        $this->kirim('POST', $url, ['reference' => 'TRF-RF-1'], $ta)
            ->assertOk()
            ->assertJson(['message' => 'Pengembalian dana ditandai selesai.'])
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.reference', 'TRF-RF-1')
            ->assertJsonPath('data.amount', 210000);

        $this->assertSame('resolved', $d->fresh()->status);
        $this->assertNotNull($d->fresh()->resolved_at);

        // Tidak bisa diselesaikan ulang, dan saldo tidak berubah lagi.
        $this->kirim('POST', $url, ['reference' => 'TRF-RF-2'], $ta)->assertStatus(422)->assertJsonValidationErrors(['refund']);
        $this->assertSame([0, 0], $this->saldo($w));
    }

    public function test_persetujuan_sebagian_membebankan_jumlah_itu_dan_sisa_penghasilan_seller_dicairkan(): void
    {
        [, $token, $o, $w] = $this->pesananSampai();
        [, $ta]            = $this->akunAdmin();

        $id = $this->buka($token, $o, ['requested_amount' => 50000]);

        // Jumlah bawaan mengikuti yang diminta pembeli; admin juga boleh menentukan sendiri.
        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/approve", ['amount' => 999999], $ta)
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'Jumlah pengembalian melebihi total pesanan.');

        $this->assertSame('open', Dispute::first()->status);   // peninjauan otomatis ikut dibatalkan
        $this->assertSame([0, 190000], $this->saldo($w));

        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/approve", [], $ta)
            ->assertOk()
            ->assertJsonPath('data.approved_amount', 50000)
            ->assertJsonPath('data.seller_charged', 50000)
            ->assertJsonPath('data.shortfall', 0)
            ->assertJsonPath('data.refunds.0.amount', 50000);

        // 50.000 dibalik dari dana tertahan dan sisa 140.000 langsung menjadi saldo yang bisa ditarik.
        $this->assertSame([140000, 0], $this->saldo($w));
        $this->assertContains('sale_release', $this->jenis($w));
        $this->assertSame('refunded', $o->fresh()->status);
    }

    public function test_pesanan_yang_sudah_selesai_membalik_saldo_tersedia_dan_kekurangan_dicatat(): void
    {
        [, $token, $o, $w] = $this->pesananSelesai();
        [$admin, $ta]      = $this->akunAdmin();

        $this->assertSame([190000, 0], $this->saldo($w));

        // Seller sudah menarik sebagian dana: tersisa 40.000.
        $w->fresh()->adjust(-150000, $admin, 'Penarikan uji');

        $this->assertSame([40000, 0], $this->saldo($w));

        $id = $this->buka($token, $o);

        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/approve", ['amount' => 100000], $ta)
            ->assertOk()
            ->assertJsonPath('data.approved_amount', 100000)
            ->assertJsonPath('data.seller_charged', 40000)
            ->assertJsonPath('data.shortfall', 60000)
            ->assertJsonPath('data.refunds.0.amount', 100000);   // pembeli tetap menerima jumlah penuh

        $this->assertSame([0, 0], $this->saldo($w));
        $this->assertSame(-40000, (int) WalletTransaction::where('type', 'sale_reversal')->value('amount'));
        $this->assertSame('available', WalletTransaction::where('type', 'sale_reversal')->value('bucket'));
        $this->assertSame('refunded', $o->fresh()->status);
    }

    public function test_platform_menanggung_dan_biaya_layanan_hanya_dikembalikan_sekali_atas_permintaan_admin(): void
    {
        [, $token, $o, $w] = $this->pesananSampai();
        [, $ta]            = $this->akunAdmin();

        $id = $this->buka($token, $o);

        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/approve", [
            'amount' => 210000,
            'bearer' => 'platform',
            'include_service_fee' => true,
            'note' => 'Kesalahan kurir',
        ], $ta)
            ->assertOk()
            ->assertJsonPath('data.bearer', 'platform')
            ->assertJsonPath('data.seller_charged', 0)
            ->assertJsonPath('data.shortfall', 0)
            ->assertJsonPath('data.service_fee_refunded', 1000)
            ->assertJsonPath('data.approved_amount', 210000)
            ->assertJsonPath('data.refunds.0.amount', 211000);

        // Seller tidak dirugikan: seluruh penghasilannya dicairkan, dan pesanan tidak membalik dana lagi.
        $this->assertSame([190000, 0], $this->saldo($w));
        $this->assertNotContains('sale_reversal', $this->jenis($w));
        $this->assertSame('refunded', $o->fresh()->status);
    }

    public function test_penolakan_wajib_beralasan_final_dan_tidak_menyentuh_dana(): void
    {
        [, $token, $o, $w] = $this->pesananSampai();
        [, $ta]            = $this->akunAdmin();

        $id = $this->buka($token, $o);

        // Komplain pertama ditinjau terlebih dahulu.
        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/review", [], $ta)
            ->assertOk()->assertJson(['message' => 'Komplain mulai ditinjau.'])->assertJsonPath('data.status', 'under_review');

        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/review", [], $ta)->assertStatus(422)->assertJsonValidationErrors(['dispute']);

        // Jumlah melebihi total pesanan ditolak dan status tetap sedang ditinjau.
        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/approve", ['amount' => 999999], $ta)->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->assertSame('under_review', Dispute::first()->status);

        // Penolakan butuh alasan.
        foreach ([[], ['reason' => 'abc'], ['reason' => str_repeat('a', 501)]] as $data) {
            $this->kirim('POST', "/api/v1/admin/disputes/{$id}/reject", $data, $ta)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        }

        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/reject", ['reason' => 'Bukti tidak menunjukkan kerusakan'], $ta)
            ->assertOk()
            ->assertJson(['message' => 'Komplain ditolak.'])
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.admin_note', 'Bukti tidak menunjukkan kerusakan');

        // Status akhir: tidak bisa diubah lagi, dan dana tidak tersentuh.
        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/approve", [], $ta)->assertStatus(422)->assertJsonValidationErrors(['dispute']);
        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/reject", ['reason' => 'Alasan penolakan kedua'], $ta)->assertStatus(422);
        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/refund", [], $ta)->assertStatus(422);

        $this->assertSame([0, 190000], $this->saldo($w));
        $this->assertSame('delivered', $o->fresh()->status);
        $this->assertSame(0, Refund::count());

        // Pembeli melihat alasan penolakan, dan tidak bisa mengajukan lagi.
        $this->kirim('GET', "/api/v1/disputes/{$id}", [], $token)->assertOk()->assertJsonPath('data.admin_note', 'Bukti tidak menunjukkan kerusakan');
        $this->ajukan($o, $token)->assertStatus(422);

        // Daftar admin: filter status, pencarian nomor pesanan, dan paginasi.
        $res = $this->kirim('GET', '/api/v1/admin/disputes', [], $ta)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$id], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame([$id], collect($this->kirim('GET', '/api/v1/admin/disputes?status=rejected', [], $ta)->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->kirim('GET', '/api/v1/admin/disputes?status=open', [], $ta)->json('data'));
        $this->assertSame([$id], collect($this->kirim('GET', '/api/v1/admin/disputes?q=' . strtolower(substr($o->number, -7)), [], $ta)->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->kirim('GET', '/api/v1/admin/disputes?q=' . urlencode('%'), [], $ta)->json('data'));
        $this->kirim('GET', '/api/v1/admin/disputes?status=abc', [], $ta)->assertStatus(422);
        $this->kirim('GET', '/api/v1/admin/disputes/' . $this->uuidAsing(), [], $ta)->assertStatus(404);
    }

    public function test_daftar_refund_mencakup_pembatalan_seller_dan_refund_yang_gagal_bisa_dibuat_ulang(): void
    {
        [, $token, $o]     = $this->pesananSampai();
        [,, $o2]          = $this->pesananSampai();
        [, $ta]            = $this->akunAdmin();

        // Refund tanpa komplain (misalnya hasil pembatalan oleh seller).
        $r1 = new Refund(['order_id' => $o2->id, 'amount' => 50000, 'method' => 'manual', 'reason' => 'Dibatalkan penjual: uji']);
        $r1->save();

        $id = $this->buka($token, $o);
        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/approve", [], $ta)->assertOk();

        $r2 = Refund::where('dispute_id', Dispute::first()->id)->first();

        $res = $this->kirim('GET', '/api/v1/admin/refunds', [], $ta)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$r2->uuid, $r1->uuid], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame([$o->number, $o->store->name, $o->buyer->name, $id], [
            $res->json('data.0.order.number'),
            $res->json('data.0.store.name'),
            $res->json('data.0.buyer.name'),
            $res->json('data.0.dispute_id'),
        ]);
        $this->assertNull($res->json('data.1.dispute_id'));

        $this->assertCount(2, $this->kirim('GET', '/api/v1/admin/refunds?status=pending', [], $ta)->json('data'));
        $this->assertCount(0, $this->kirim('GET', '/api/v1/admin/refunds?status=completed', [], $ta)->json('data'));
        $this->kirim('GET', '/api/v1/admin/refunds?status=abc', [], $ta)->assertStatus(422);

        // Refund tanpa komplain diselesaikan.
        $this->kirim('POST', "/api/v1/admin/refunds/{$r1->uuid}/complete", ['reference' => 'TRF-1'], $ta)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->kirim('POST', "/api/v1/admin/refunds/{$r1->uuid}/complete", ['reference' => 'TRF-1'], $ta)->assertStatus(422);

        // Refund hasil komplain gagal: alasan wajib dan status akhir.
        foreach ([[], ['reason' => 'abc']] as $data) {
            $this->kirim('POST', "/api/v1/admin/refunds/{$r2->uuid}/fail", $data, $ta)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        }

        $this->kirim('POST', "/api/v1/admin/refunds/{$r2->uuid}/fail", ['reason' => 'Rekening pembeli tidak valid'], $ta)
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', 'Rekening pembeli tidak valid');

        $this->kirim('POST', "/api/v1/admin/refunds/{$r2->uuid}/fail", ['reason' => 'Alasan yang kedua'], $ta)->assertStatus(422);
        $this->kirim('POST', "/api/v1/admin/refunds/{$r2->uuid}/complete", ['reference' => 'TRF-9'], $ta)->assertStatus(422);

        // Komplain tetap "approved" sampai ada refund yang berhasil.
        $this->assertSame('approved', Dispute::first()->status);

        // Refund pengganti dibuat dengan jumlah yang sama, dan tidak bisa dua kali selama masih menunggu.
        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/refund", [], $ta)
            ->assertStatus(201)
            ->assertJson(['message' => 'Pengembalian dana baru dibuat.'])
            ->assertJsonCount(2, 'data.refunds')
            ->assertJsonPath('data.refunds.1.amount', 210000)
            ->assertJsonPath('data.refunds.1.status', 'pending');

        $this->kirim('POST', "/api/v1/admin/disputes/{$id}/refund", [], $ta)->assertStatus(422)->assertJsonValidationErrors(['dispute']);

        $baru = Refund::where('dispute_id', Dispute::first()->id)->where('status', 'pending')->first();

        $this->kirim('POST', "/api/v1/admin/refunds/{$baru->uuid}/complete", ['reference' => 'TRF-2'], $ta)->assertOk();

        $this->assertSame('resolved', Dispute::first()->status);
    }
}
