<?php

namespace Tests\Feature;

use App\Models\CheckoutGroup;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Store;
use App\Models\StoreBankAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Menguji Batch 7: notifikasi, komplain/refund, dan keuangan seller.
 * Berjalan di SQLite memori, tidak menyentuh MySQL.
 */
class Batch7DatabaseTest extends TestCase
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

    private function buatPesanan(): Order
    {
        $pembeli = $this->buatUser();
        $toko    = $this->buatToko();

        $grup = CheckoutGroup::create([
            'user_id'     => $pembeli->id,
            'number'      => CheckoutGroup::generateNumber(),
            'subtotal'    => 100000,
            'grand_total' => 100000,
        ]);

        return Order::create([
            'checkout_group_id' => $grup->id,
            'user_id'           => $pembeli->id,
            'store_id'          => $toko->id,
            'number'            => Order::generateNumber($grup, 1),
            'shipping_address'  => ['city' => 'Sleman'],
            'subtotal'          => 100000,
            'grand_total'       => 100000,
        ])->fresh();
    }

    private function buatKomplain(?Order $pesanan = null): Dispute
    {
        $pesanan ??= $this->buatPesanan();

        $komplain = new Dispute([
            'type'             => Dispute::TYPE_REFUND,
            'reason_code'      => 'item_damaged',
            'description'      => 'Barang datang dalam keadaan rusak.',
            'requested_amount' => 100000,
        ]);
        $komplain->forceFill([
            'order_id' => $pesanan->id,
            'user_id'  => $pesanan->user_id,
            'store_id' => $pesanan->store_id,
        ])->save();

        return $komplain->fresh();
    }

    private function buatDompet(?Store $toko = null): Wallet
    {
        $toko ??= $this->buatToko();

        return Wallet::create(['store_id' => $toko->id])->fresh();
    }

    private function buatRekening(Store $toko): StoreBankAccount
    {
        $rekening = new StoreBankAccount([
            'bank_code' => 'bca',
            'bank_name' => 'BCA',
            'account_name' => 'Budi Santoso',
        ]);
        $rekening->store_id = $toko->id;
        $rekening->setAccountNumber('1234-5678-90');
        $rekening->save();

        return $rekening->fresh();
    }

    // ------------------------------------------------------------- NOTIFIKASI

    public function test_notifikasi_bawaan_laravel_bisa_disimpan_dan_ditandai_dibaca(): void
    {
        $user = $this->buatUser();

        $user->notifications()->create([
            'id'   => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Contoh',
            'data' => ['judul' => 'Pesanan dibayar'],
        ]);

        $this->assertSame(1, $user->unreadNotifications()->count());

        $notifikasi = $user->notifications()->first();
        $this->assertSame('Pesanan dibayar', $notifikasi->data['judul']);

        $notifikasi->markAsRead();

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_pengaturan_notifikasi_punya_nilai_bawaan_dan_bisa_diubah(): void
    {
        $user = $this->buatUser();

        $this->assertTrue(NotificationSetting::channelEnabled($user->id, 'order', 'push'));
        $this->assertTrue(NotificationSetting::channelEnabled($user->id, 'chat', 'email'));
        $this->assertFalse(NotificationSetting::channelEnabled($user->id, 'promo', 'push'));

        NotificationSetting::create(['user_id' => $user->id, 'category' => 'order', 'push_enabled' => false]);
        NotificationSetting::create(['user_id' => $user->id, 'category' => 'promo', 'push_enabled' => true]);

        $this->assertFalse(NotificationSetting::channelEnabled($user->id, 'order', 'push'));
        $this->assertTrue(NotificationSetting::channelEnabled($user->id, 'order', 'email'));
        $this->assertTrue(NotificationSetting::channelEnabled($user->id, 'promo', 'push'));

        try {
            NotificationSetting::channelEnabled($user->id, 'tidak-ada', 'push');
            $this->fail('Seharusnya InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Kategori', $e->getMessage());
        }

        try {
            NotificationSetting::channelEnabled($user->id, 'order', 'sms');
            $this->fail('Seharusnya InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Saluran', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        NotificationSetting::create(['user_id' => $user->id, 'category' => 'order']);
    }

    // ---------------------------------------------------------------- KOMPLAIN

    public function test_komplain_punya_default_relasi_dan_bukti(): void
    {
        $pesanan = $this->buatPesanan();

        $coba = new Dispute([
            'type' => 'refund',
            'reason_code' => 'x',
            'description' => 'y',
            'status' => 'approved',
            'order_id' => 999,
            'approved_amount' => 999999,
        ]);
        $this->assertNull($coba->status);
        $this->assertNull($coba->order_id);
        $this->assertNull($coba->approved_amount);

        $komplain = $this->buatKomplain($pesanan);

        $this->assertSame(36, strlen($komplain->uuid));
        $this->assertSame(Dispute::STATUS_OPEN, $komplain->status);
        $this->assertSame($pesanan->id, $komplain->order->id);
        $this->assertSame($pesanan->user_id, $komplain->buyer->id);
        $this->assertSame($pesanan->store_id, $komplain->store->id);

        $komplain->evidences()->create([
            'user_id' => $pesanan->user_id,
            'path' => 'disputes/a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1000,
        ]);
        $this->assertSame(1, $komplain->evidences()->count());

        $komplain->delete();
        $this->assertSame(0, DisputeEvidence::count());
    }

    public function test_alur_komplain_disetujui_sampai_selesai(): void
    {
        $admin    = $this->buatUser();
        $komplain = $this->buatKomplain();

        $komplain->startReview($admin);
        $this->assertSame(Dispute::STATUS_UNDER_REVIEW, $komplain->status);
        $this->assertSame($admin->id, $komplain->reviewer->id);

        $komplain->approve($admin, 60000, 'Kerusakan terbukti dari foto.');
        $this->assertSame(Dispute::STATUS_APPROVED, $komplain->status);
        $this->assertSame(60000, $komplain->approved_amount);
        $this->assertSame('Kerusakan terbukti dari foto.', $komplain->admin_note);
        $this->assertNull($komplain->resolved_at);

        $komplain->resolve($admin);
        $this->assertSame(Dispute::STATUS_RESOLVED, $komplain->status);
        $this->assertNotNull($komplain->resolved_at);
    }

    public function test_perpindahan_status_komplain_yang_tidak_valid_ditolak(): void
    {
        $admin    = $this->buatUser();
        $komplain = $this->buatKomplain();

        try {
            $komplain->approve($admin, 1000);   // belum diperiksa
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('open', $e->getMessage());
        }

        $komplain->startReview($admin);

        try {
            $komplain->startReview($admin);   // sudah diperiksa
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('under_review', $e->getMessage());
        }

        try {
            $komplain->approve($admin, 100001);   // melebihi total pesanan 100000
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('melebihi', $e->getMessage());
        }

        try {
            $komplain->approve($admin, -1);
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('negatif', $e->getMessage());
        }

        $this->assertSame(Dispute::STATUS_UNDER_REVIEW, $komplain->fresh()->status);
        $this->assertNull($komplain->fresh()->approved_amount);
    }

    public function test_komplain_ditolak_wajib_alasan_dan_menjadi_status_akhir(): void
    {
        $admin    = $this->buatUser();
        $komplain = $this->buatKomplain();
        $komplain->startReview($admin);

        try {
            $komplain->reject($admin, '   ');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Alasan', $e->getMessage());
        }

        $komplain->reject($admin, 'Bukti tidak cukup.');

        $this->assertSame(Dispute::STATUS_REJECTED, $komplain->status);
        $this->assertSame('Bukti tidak cukup.', $komplain->admin_note);
        $this->assertNotNull($komplain->resolved_at);

        $this->expectException(DomainException::class);
        $komplain->approve($admin, 1000);
    }

    public function test_refund_dari_pending_ke_selesai_atau_gagal_hanya_sekali(): void
    {
        $admin    = $this->buatUser();
        $komplain = $this->buatKomplain();

        $refund = Refund::create([
            'order_id' => $komplain->order_id,
            'dispute_id' => $komplain->id,
            'amount' => 60000,
            'reason' => 'Barang rusak',
            'status' => 'completed',
        ])->fresh();

        $this->assertSame(36, strlen($refund->uuid));
        $this->assertSame(Refund::STATUS_PENDING, $refund->status);   // status tidak bisa diisi massal
        $this->assertSame(Refund::METHOD_MANUAL, $refund->method);

        $refund->complete($admin, 'REF-123');
        $this->assertSame(Refund::STATUS_COMPLETED, $refund->status);
        $this->assertSame('REF-123', $refund->reference);
        $this->assertSame($admin->id, $refund->processor->id);
        $this->assertNotNull($refund->processed_at);

        try {
            $refund->complete($admin, 'REF-LAIN');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('completed', $e->getMessage());
        }

        $gagal = Refund::create(['order_id' => $komplain->order_id, 'amount' => 1000]);

        try {
            $gagal->fail($admin, '');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Alasan', $e->getMessage());
        }

        $gagal->fail($admin, 'Rekening tidak valid.');
        $this->assertSame(Refund::STATUS_FAILED, $gagal->status);
        $this->assertSame('Rekening tidak valid.', $gagal->failure_reason);

        // Komplain dihapus: refund tetap ada, hanya tautannya yang kosong.
        $komplain->delete();
        $this->assertNull($refund->fresh()->dispute_id);
    }

    // --------------------------------------------------------------- KEUANGAN

    public function test_rekening_toko_menyimpan_nomor_terenkripsi_dan_empat_digit_terakhir(): void
    {
        $rekening = $this->buatRekening($this->buatToko());

        $mentah = DB::table('store_bank_accounts')->where('id', $rekening->id)->first();
        $this->assertStringNotContainsString('1234567890', $mentah->account_number_encrypted);
        $this->assertSame('7890', $mentah->account_number_last4);

        $this->assertSame('1234567890', $rekening->account_number_encrypted);
        $this->assertArrayNotHasKey('account_number_encrypted', $rekening->toArray());
        $this->assertTrue($rekening->toArray()['account_number_last4'] === '7890');

        $this->expectException(DomainException::class);
        (new StoreBankAccount)->setAccountNumber('12');
    }

    public function test_dompet_satu_per_toko_dan_saldo_tidak_bisa_diisi_massal(): void
    {
        $toko = $this->buatToko();

        $dompet = Wallet::create(['store_id' => $toko->id, 'balance' => 999999, 'pending_balance' => 5])->fresh();

        $this->assertSame(0, $dompet->balance);
        $this->assertSame(0, $dompet->pending_balance);
        $this->assertSame($toko->id, $dompet->store->id);

        $this->expectException(QueryException::class);
        Wallet::create(['store_id' => $toko->id]);
    }

    public function test_dana_ditahan_lalu_dicairkan_dan_tercatat_di_ledger(): void
    {
        $dompet  = $this->buatDompet();
        $pesanan = $this->buatPesanan();

        $this->assertTrue($dompet->holdSale(95000, $pesanan, 'Penjualan pesanan'));
        $this->assertSame(95000, $dompet->pending_balance);
        $this->assertSame(0, $dompet->balance);

        $this->assertTrue($dompet->releaseSale(95000, $pesanan));
        $this->assertSame(0, $dompet->pending_balance);
        $this->assertSame(95000, $dompet->balance);

        $baris = $dompet->transactions()->get();
        $this->assertCount(3, $baris);

        $this->assertSame(['pending', 'sale_hold', 95000, 95000], [$baris[0]->bucket, $baris[0]->type, $baris[0]->amount, $baris[0]->balance_after]);
        $this->assertSame(['pending', 'sale_release', -95000, 0], [$baris[1]->bucket, $baris[1]->type, $baris[1]->amount, $baris[1]->balance_after]);
        $this->assertSame(['available', 'sale_release', 95000, 95000], [$baris[2]->bucket, $baris[2]->type, $baris[2]->amount, $baris[2]->balance_after]);
        $this->assertSame($pesanan->id, $baris[0]->reference->id);
    }

    public function test_perubahan_saldo_yang_sama_untuk_referensi_yang_sama_hanya_sekali(): void
    {
        $dompet  = $this->buatDompet();
        $pesanan = $this->buatPesanan();

        $this->assertTrue($dompet->holdSale(95000, $pesanan));
        $this->assertFalse($dompet->holdSale(95000, $pesanan));   // webhook/scheduler berjalan dua kali
        $this->assertSame(95000, $dompet->pending_balance);

        $this->assertTrue($dompet->releaseSale(95000, $pesanan));
        $this->assertFalse($dompet->releaseSale(95000, $pesanan));
        $this->assertSame(95000, $dompet->balance);
        $this->assertSame(0, $dompet->pending_balance);

        $this->assertSame(3, WalletTransaction::count());
    }

    public function test_saldo_tidak_boleh_minus_dan_tidak_ada_yang_berubah_saat_gagal(): void
    {
        $dompet  = $this->buatDompet();
        $pesanan = $this->buatPesanan();
        $admin   = $this->buatUser();

        try {
            $dompet->releaseSale(1000, $pesanan);   // tidak ada dana pending
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Saldo tidak cukup', $e->getMessage());
        }

        try {
            $dompet->adjust(-5000, $admin, 'Koreksi');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Saldo tidak cukup', $e->getMessage());
        }

        $this->assertSame(0, $dompet->fresh()->balance);
        $this->assertSame(0, $dompet->fresh()->pending_balance);
        $this->assertSame(0, WalletTransaction::count());

        $this->expectException(DomainException::class);
        $dompet->holdSale(0, $pesanan);
    }

    public function test_pembalikan_penjualan_dari_saldo_pending_atau_available(): void
    {
        $dompet = $this->buatDompet();
        $a      = $this->buatPesanan();
        $b      = $this->buatPesanan();

        // Pesanan A dibatalkan sebelum selesai: dana ditarik dari pending.
        $dompet->holdSale(40000, $a);
        $this->assertTrue($dompet->reverseSale(40000, $a, Wallet::BUCKET_PENDING));
        $this->assertFalse($dompet->reverseSale(40000, $a, Wallet::BUCKET_PENDING));   // tidak dua kali
        $this->assertSame(0, $dompet->pending_balance);

        // Pesanan B direfund setelah selesai: dana ditarik dari available.
        $dompet->holdSale(70000, $b);
        $dompet->releaseSale(70000, $b);
        $this->assertSame(70000, $dompet->balance);
        $this->assertTrue($dompet->reverseSale(70000, $b, Wallet::BUCKET_AVAILABLE));
        $this->assertSame(0, $dompet->balance);

        $this->expectException(DomainException::class);
        $dompet->reverseSale(1000, $a, 'bukan-bucket');
    }

    public function test_koreksi_manual_wajib_keterangan_dan_tercatat_siapa_adminnya(): void
    {
        $dompet = $this->buatDompet();
        $admin  = $this->buatUser();

        foreach ([[0, 'Koreksi'], [1000, '   ']] as [$delta, $ket]) {
            try {
                $dompet->adjust($delta, $admin, $ket);
                $this->fail('Seharusnya DomainException.');
            } catch (DomainException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertTrue($dompet->adjust(5000, $admin, 'Koreksi manual'));
        $this->assertTrue($dompet->adjust(-2000, $admin, 'Koreksi selisih'));   // tanpa referensi: boleh berulang
        $this->assertSame(3000, $dompet->balance);

        $baris = $dompet->transactions()->get();
        $this->assertCount(2, $baris);
        $this->assertSame($admin->id, $baris[0]->creator->id);
        $this->assertSame('Koreksi manual', $baris[0]->description);
    }

    public function test_pengajuan_penarikan_mengurangi_saldo_dan_menyimpan_snapshot_rekening(): void
    {
        $toko     = $this->buatToko();
        $pemilik  = $toko->owner;
        $dompet   = $this->buatDompet($toko);
        $rekening = $this->buatRekening($toko);
        $admin    = $this->buatUser();

        $dompet->adjust(200000, $admin, 'Saldo awal uji');

        $penarikan = $dompet->requestWithdrawal($pemilik, $rekening, 150000);

        $this->assertSame(36, strlen($penarikan->uuid));
        $this->assertSame(Withdrawal::STATUS_PENDING, $penarikan->status);
        $this->assertSame(150000, $penarikan->amount);
        $this->assertSame(50000, $dompet->balance);
        $this->assertSame('BCA', $penarikan->bank_name);
        $this->assertSame('7890', $penarikan->account_number_last4);
        $this->assertSame('1234567890', $penarikan->account_number_encrypted);
        $this->assertArrayNotHasKey('account_number_encrypted', $penarikan->toArray());
        $this->assertSame($pemilik->id, $penarikan->requester->id);

        $ledger = $dompet->transactions()->get()->last();
        $this->assertSame('withdrawal', $ledger->type);
        $this->assertSame(-150000, $ledger->amount);
        $this->assertSame($penarikan->id, $ledger->reference->id);

        // Di bawah minimum.
        try {
            $dompet->requestWithdrawal($pemilik, $rekening, 10000);
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('minimal', $e->getMessage());
        }

        // Melebihi saldo: tidak ada permintaan yang tersimpan (transaksi dibatalkan).
        try {
            $dompet->requestWithdrawal($pemilik, $rekening, 60000);
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Saldo tidak cukup', $e->getMessage());
        }
        $this->assertSame(1, Withdrawal::count());
        $this->assertSame(50000, $dompet->fresh()->balance);

        // Rekening milik toko lain.
        $rekeningLain = $this->buatRekening($this->buatToko());
        $this->expectException(DomainException::class);
        $dompet->requestWithdrawal($pemilik, $rekeningLain, 50000);
    }

    public function test_penarikan_disetujui_lalu_dibayar_dan_tidak_bisa_ditolak_setelahnya(): void
    {
        $toko     = $this->buatToko();
        $dompet   = $this->buatDompet($toko);
        $rekening = $this->buatRekening($toko);
        $admin    = $this->buatUser();

        $dompet->adjust(100000, $admin, 'Saldo awal uji');
        $penarikan = $dompet->requestWithdrawal($toko->owner, $rekening, 100000);

        try {
            $penarikan->markPaid($admin, 'TRX-1');   // belum disetujui
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('pending', $e->getMessage());
        }

        $penarikan->approve($admin);
        $this->assertSame(Withdrawal::STATUS_APPROVED, $penarikan->status);

        try {
            $penarikan->markPaid($admin, '  ');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('referensi', $e->getMessage());
        }

        $penarikan->markPaid($admin, 'TRX-1');
        $this->assertSame(Withdrawal::STATUS_PAID, $penarikan->status);
        $this->assertSame('TRX-1', $penarikan->transfer_reference);
        $this->assertNotNull($penarikan->paid_at);
        $this->assertSame($admin->id, $penarikan->reviewer->id);

        try {
            $penarikan->reject($admin, 'Terlambat');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('paid', $e->getMessage());
        }

        $this->assertSame(0, $dompet->fresh()->balance);   // uang sudah keluar, tidak kembali
    }

    public function test_penarikan_ditolak_mengembalikan_dana_tepat_sekali(): void
    {
        $toko     = $this->buatToko();
        $dompet   = $this->buatDompet($toko);
        $rekening = $this->buatRekening($toko);
        $admin    = $this->buatUser();

        $dompet->adjust(200000, $admin, 'Saldo awal uji');
        $penarikan = $dompet->requestWithdrawal($toko->owner, $rekening, 150000);
        $this->assertSame(50000, $dompet->balance);

        try {
            $penarikan->reject($admin, '');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Alasan', $e->getMessage());
        }
        $this->assertSame(50000, $dompet->fresh()->balance);

        $penarikan->reject($admin, 'Nama rekening tidak sesuai.');

        $this->assertSame(Withdrawal::STATUS_REJECTED, $penarikan->status);
        $this->assertSame('Nama rekening tidak sesuai.', $penarikan->rejection_reason);
        $this->assertSame(200000, $dompet->fresh()->balance);

        // Ditolak dua kali: ditolak dengan DomainException, saldo tidak bertambah lagi.
        try {
            $penarikan->reject($admin, 'Sekali lagi');
            $this->fail('Seharusnya DomainException.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('rejected', $e->getMessage());
        }
        $this->assertSame(200000, $dompet->fresh()->balance);

        $this->assertSame(
            ['adjustment', 'withdrawal', 'withdrawal_reversal'],
            $dompet->transactions()->pluck('type')->all()
        );
    }

    public function test_dompet_yang_punya_ledger_tidak_bisa_dihapus(): void
    {
        $dompet = $this->buatDompet();
        $dompet->adjust(1000, $this->buatUser(), 'Saldo awal uji');

        $this->expectException(QueryException::class);
        $dompet->delete();
    }
}
