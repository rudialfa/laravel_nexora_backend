<?php

namespace Tests\Feature;

use App\Models\Withdrawal;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\SiapkanKeuangan;
use Tests\TestCase;

/**
 * Menguji penarikan dana: pengajuan seller dan peninjauan admin lewat HTTP.
 */
class WithdrawalApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanKeuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturPengiriman();
    }

    /** Pengajuan dengan penghitung laju dibersihkan: tes ini mengirim banyak permintaan. */
    private function ajukan(string $token, array $data)
    {
        Cache::flush();

        return $this->kirim('POST', '/api/v1/seller/withdrawals', $data, $token);
    }

    public function test_endpoint_penarikan_butuh_login_akun_aktif_dan_peran_yang_sesuai(): void
    {
        $u = $this->uuidAsing();

        $seller = [['GET', '/api/v1/seller/withdrawals'], ['POST', '/api/v1/seller/withdrawals'], ['GET', "/api/v1/seller/withdrawals/{$u}"]];
        $admin  = [
            ['GET', '/api/v1/admin/withdrawals'],
            ['GET', "/api/v1/admin/withdrawals/{$u}"],
            ['POST', "/api/v1/admin/withdrawals/{$u}/approve"],
            ['POST', "/api/v1/admin/withdrawals/{$u}/pay"],
            ['POST', "/api/v1/admin/withdrawals/{$u}/reject"],
        ];

        foreach (array_merge($seller, $admin) as [$method, $uri]) {
            $this->kirim($method, $uri)->assertStatus(401);
        }

        [, $nonaktif] = $this->pembeli('suspended');
        [, $biasa]    = $this->pembeli();
        [$tokenSeller] = $this->penjualBerdana(0);

        foreach (array_merge($seller, $admin) as [$method, $uri]) {
            $this->kirim($method, $uri, [], $nonaktif)->assertStatus(403);
        }

        foreach ($seller as [$method, $uri]) {
            $this->kirim($method, $uri, [], $biasa)->assertStatus(403);
        }

        foreach ($admin as [$method, $uri]) {
            $this->kirim($method, $uri, [], $biasa)->assertStatus(403);
            $this->kirim($method, $uri, [], $tokenSeller)->assertStatus(403);
        }
    }

    public function test_seller_mengajukan_penarikan_dengan_aturan_saldo_minimal_rekening_dan_batas_pengajuan(): void
    {
        [$token, $toko, $w] = $this->penjualBerdana(300000);
        [, $tokoLain]       = $this->penjualBerdana(0);

        $rek      = $this->rekening($toko);
        $rekLain  = $this->rekening($tokoLain, '9999999999');

        // Masukan tidak valid.
        $this->ajukan($token, [])->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors(['bank_account_id', 'amount']);
        $this->ajukan($token, ['bank_account_id' => 'abc', 'amount' => 100000])->assertStatus(422)->assertJsonValidationErrors(['bank_account_id']);
        $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 0])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        // Di bawah minimal, saldo tidak cukup, serta rekening toko lain atau tidak ada.
        $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 49999])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'Penarikan minimal Rp50.000.');
        $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 400000])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'Saldo tidak cukup.');
        $this->ajukan($token, ['bank_account_id' => $rekLain->uuid, 'amount' => 100000])->assertStatus(404);
        $this->ajukan($token, ['bank_account_id' => $this->uuidAsing(), 'amount' => 100000])->assertStatus(404);

        $this->assertSame(0, Withdrawal::count());
        $this->assertSame(300000, (int) $w->fresh()->balance);

        // Berhasil: saldo langsung berkurang, rekening disimpan sebagai salinan, dan nomor lengkap tidak bocor.
        $res = $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 120000])
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Pengajuan penarikan dibuat.'])
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 120000)
            ->assertJsonPath('data.bank_name', 'Bank Central Asia')
            ->assertJsonPath('data.account_name', 'Budi Santoso')
            ->assertJsonPath('data.account_number_last4', '7890')
            ->assertJsonMissingPath('data.account_number')
            ->assertJsonMissingPath('data.store');

        $this->assertStringNotContainsString('1234567890', $res->getContent());

        $wd = Withdrawal::first();

        $this->assertSame($wd->uuid, $res->json('data.id'));
        $this->assertSame('pending', $wd->status);
        $this->assertSame(180000, (int) $w->fresh()->balance);

        $t = WalletTransaction::where('type', 'withdrawal')->first();

        $this->assertSame(['available', -120000, 180000], [$t->bucket, (int) $t->amount, (int) $t->balance_after]);

        // Dua pengajuan lagi diterima, yang keempat ditolak karena sudah ada tiga yang menunggu.
        $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 50000])->assertStatus(201);
        $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 50000])->assertStatus(201);

        $res = $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 50000])->assertStatus(422);

        $this->assertStringStartsWith('Anda masih memiliki 3 pengajuan', $res->json('errors.amount.0'));
        $this->assertSame(3, Withdrawal::count());
        $this->assertSame(80000, (int) $w->fresh()->balance);

        $this->kirim('GET', '/api/v1/seller/wallet', [], $token)
            ->assertOk()->assertJsonPath('data.withdrawals_pending_count', 3)->assertJsonPath('data.withdrawals_pending_amount', 220000);
    }

    public function test_admin_meninjau_menyetujui_membayar_dan_menolak_penarikan(): void
    {
        [$token, $toko, $w] = $this->penjualBerdana(300000);
        [, $tokenAdmin]     = $this->akunAdmin();

        $rek = $this->rekening($toko);

        $id1 = $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 100000])->assertStatus(201)->json('data.id');
        $id2 = $this->ajukan($token, ['bank_account_id' => $rek->uuid, 'amount' => 80000])->assertStatus(201)->json('data.id');

        // Daftar: terbaru lebih dulu, dengan filter dan pencarian.
        $res = $this->kirim('GET', '/api/v1/admin/withdrawals', [], $tokenAdmin)->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$id2, $id1], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame([2, $toko->name], [$res->json('meta.total'), $res->json('data.0.store.name')]);
        $this->assertCount(2, $this->kirim('GET', '/api/v1/admin/withdrawals?status=pending', [], $tokenAdmin)->json('data'));
        $this->assertCount(0, $this->kirim('GET', '/api/v1/admin/withdrawals?status=paid', [], $tokenAdmin)->json('data'));
        $this->assertCount(2, $this->kirim('GET', '/api/v1/admin/withdrawals?q=' . urlencode(strtolower($toko->name)), [], $tokenAdmin)->json('data'));
        $this->assertCount(0, $this->kirim('GET', '/api/v1/admin/withdrawals?q=' . urlencode('%'), [], $tokenAdmin)->json('data'));
        $this->kirim('GET', '/api/v1/admin/withdrawals?status=abc', [], $tokenAdmin)->assertStatus(422);

        // Detail memuat nomor rekening lengkap untuk keperluan transfer.
        $this->kirim('GET', "/api/v1/admin/withdrawals/{$id1}", [], $tokenAdmin)
            ->assertOk()
            ->assertJsonPath('data.account_number', '1234567890')
            ->assertJsonPath('data.store.name', $toko->name)
            ->assertJsonPath('data.status', 'pending');

        // Harus disetujui sebelum dibayar.
        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id1}/pay", ['transfer_reference' => 'TRF-001'], $tokenAdmin)
            ->assertStatus(422)->assertJsonValidationErrors(['withdrawal']);

        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id1}/approve", [], $tokenAdmin)
            ->assertOk()->assertJson(['message' => 'Penarikan disetujui.'])->assertJsonPath('data.status', 'approved');

        $this->assertNotNull(Withdrawal::where('uuid', $id1)->value('reviewed_at'));

        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id1}/approve", [], $tokenAdmin)->assertStatus(422)->assertJsonValidationErrors(['withdrawal']);
        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id1}/pay", [], $tokenAdmin)->assertStatus(422)->assertJsonValidationErrors(['transfer_reference']);

        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id1}/pay", ['transfer_reference' => 'TRF-001'], $tokenAdmin)
            ->assertOk()
            ->assertJson(['message' => 'Penarikan ditandai sudah dibayar.'])
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.transfer_reference', 'TRF-001');

        $this->assertNotNull(Withdrawal::where('uuid', $id1)->value('paid_at'));

        // Yang sudah dibayar tidak bisa dibayar ulang atau ditolak.
        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id1}/pay", ['transfer_reference' => 'TRF-002'], $tokenAdmin)->assertStatus(422);
        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id1}/reject", ['reason' => 'Alasan penolakan uji'], $tokenAdmin)->assertStatus(422);

        // Penolakan butuh alasan, lalu mengembalikan dana tepat sekali.
        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id2}/reject", [], $tokenAdmin)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertSame(120000, (int) $w->fresh()->balance);

        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id2}/reject", ['reason' => 'Nama rekening tidak sesuai'], $tokenAdmin)
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Nama rekening tidak sesuai');

        $this->assertSame(200000, (int) $w->fresh()->balance);
        $this->assertSame(1, WalletTransaction::where('type', 'withdrawal_reversal')->count());
        $this->assertSame(80000, (int) WalletTransaction::where('type', 'withdrawal_reversal')->value('amount'));

        $this->kirim('POST', "/api/v1/admin/withdrawals/{$id2}/reject", ['reason' => 'Nama rekening tidak sesuai'], $tokenAdmin)->assertStatus(422);

        $this->assertSame(200000, (int) $w->fresh()->balance);
        $this->assertSame(1, WalletTransaction::where('type', 'withdrawal_reversal')->count());

        // Tidak ada atau bukan UUID.
        $this->kirim('GET', '/api/v1/admin/withdrawals/' . $this->uuidAsing(), [], $tokenAdmin)->assertStatus(404);
        $this->kirim('POST', '/api/v1/admin/withdrawals/' . $this->uuidAsing() . '/approve', [], $tokenAdmin)->assertStatus(404);
    }

    public function test_seller_melihat_status_penarikannya_sendiri_tanpa_data_internal_dan_tidak_melihat_milik_toko_lain(): void
    {
        [$token, $toko, $w, $admin] = $this->penjualBerdana(300000);
        [$tokenLain]                = $this->penjualBerdana(0);

        $rek = $this->rekening($toko);

        $dibayar  = $w->requestWithdrawal($toko->owner, $rek, 60000);
        $ditolak  = $w->fresh()->requestWithdrawal($toko->owner, $rek, 70000);

        $dibayar->approve($admin);
        $dibayar->markPaid($admin, 'TRF-9');
        $ditolak->reject($admin, 'Alasan uji penolakan');

        $res = $this->kirim('GET', '/api/v1/seller/withdrawals', [], $token)->assertOk()->assertJson(['success' => true]);

        $this->assertSame(2, $res->json('meta.total'));
        $this->assertSame(['rejected', 'paid'], collect($res->json('data'))->pluck('status')->all());
        $this->assertCount(1, $this->kirim('GET', '/api/v1/seller/withdrawals?status=paid', [], $token)->json('data'));
        $this->assertStringNotContainsString('1234567890', $res->getContent());

        $this->kirim('GET', "/api/v1/seller/withdrawals/{$dibayar->uuid}", [], $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.transfer_reference', 'TRF-9')
            ->assertJsonMissingPath('data.store')
            ->assertJsonMissingPath('data.requested_by')
            ->assertJsonMissingPath('data.account_number');

        $this->kirim('GET', "/api/v1/seller/withdrawals/{$ditolak->uuid}", [], $token)
            ->assertOk()->assertJsonPath('data.rejection_reason', 'Alasan uji penolakan');

        // Seller lain tidak melihat dan tidak bisa membuka penarikan ini.
        $this->assertSame([], $this->kirim('GET', '/api/v1/seller/withdrawals', [], $tokenLain)->assertOk()->json('data'));
        $this->kirim('GET', "/api/v1/seller/withdrawals/{$dibayar->uuid}", [], $tokenLain)->assertStatus(404);
        $this->kirim('GET', '/api/v1/seller/withdrawals/' . $this->uuidAsing(), [], $token)->assertStatus(404);
    }
}
