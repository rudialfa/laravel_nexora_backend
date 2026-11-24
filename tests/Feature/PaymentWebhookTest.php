<?php

namespace Tests\Feature;

use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Models\WebhookLog;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SiapkanPembayaran;
use Tests\TestCase;

/**
 * Menguji notifikasi Midtrans, penutupan checkout, dan perintah simulasi. Midtrans dipalsukan.
 */
class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanPembayaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturMidtrans();
    }

    /** @return array{0: \App\Models\User, 1: string, 2: CheckoutGroup, 3: Payment} checkout dengan tagihan VA BCA */
    private function siap(): array
    {
        [$user, $token] = $this->pembeli();
        $grup           = $this->checkout($user);
        $this->palsuMidtrans();
        $this->bayar($grup, $token)->assertStatus(201);

        return [$user, $token, $grup, Payment::orderByDesc('id')->first()];
    }

    private function terima(array $payload)
    {
        return $this->kirim('POST', '/api/v1/webhooks/midtrans', $payload);
    }

    private function nominal(Payment $p): int
    {
        return (int) $p->gross_amount;
    }

    public function test_pembayaran_berhasil_melunasi_checkout_pesanan_dan_voucher(): void
    {
        [,, $grup, $p] = $this->siap();

        $this->terima($this->notifikasi($p->midtrans_order_id, $this->nominal($p), 'settlement'))
            ->assertOk()->assertJson(['success' => true]);

        $p = $p->fresh();

        $this->assertSame('paid', $p->status);
        $this->assertNotNull($p->paid_at);
        $this->assertSame('settlement', $p->transaction_status);

        $g = $grup->fresh();

        $this->assertSame('paid', $g->status);
        $this->assertNotNull($g->paid_at);
        $this->assertSame('paid', Order::first()->status);
        $this->assertSame('used', VoucherUsage::first()->status);
        $this->assertSame(1, (int) Voucher::first()->used_count);
        $this->assertSame(8, (int) ProductVariant::first()->stock);   // stok tetap tertahan sampai pesanan selesai

        $log = WebhookLog::first();

        $this->assertSame('midtrans', $log->provider);
        $this->assertSame('processed', $log->processing_status);
        $this->assertTrue((bool) $log->is_valid);
        $this->assertSame($p->midtrans_order_id, $log->external_id);
    }

    public function test_notifikasi_dengan_signature_salah_atau_tidak_lengkap_ditolak(): void
    {
        [,, $grup, $p] = $this->siap();

        $n                  = $this->notifikasi($p->midtrans_order_id, $this->nominal($p), 'settlement');
        $n['signature_key'] = 'salah';

        $this->terima($n)->assertStatus(403)->assertJson(['success' => false]);
        $this->terima([])->assertStatus(400)->assertJson(['success' => false]);

        $this->assertSame('pending', $p->fresh()->status);
        $this->assertSame('pending_payment', $grup->fresh()->status);
        $this->assertSame('pending_payment', Order::first()->status);
        $this->assertSame(2, WebhookLog::count());
        $this->assertSame(['failed', 'failed'], WebhookLog::orderBy('id')->pluck('processing_status')->all());
        $this->assertFalse((bool) WebhookLog::orderBy('id')->first()->is_valid);
    }

    public function test_notifikasi_yang_dikirim_ulang_tidak_menggandakan_apa_pun(): void
    {
        [,, $grup, $p] = $this->siap();

        $n = $this->notifikasi($p->midtrans_order_id, $this->nominal($p), 'settlement');

        $this->terima($n)->assertOk();

        $riwayat = Order::first()->statusHistories()->count();

        $this->terima($n)->assertOk()->assertJson(['message' => 'Notifikasi sudah pernah diproses.']);

        $this->assertSame($riwayat, Order::first()->statusHistories()->count());
        $this->assertSame(1, VoucherUsage::count());
        $this->assertSame('used', VoucherUsage::first()->status);
        $this->assertSame(1, (int) Voucher::first()->used_count);
        $this->assertSame('paid', $grup->fresh()->status);
        $this->assertSame(['processed', 'ignored'], WebhookLog::orderBy('id')->pluck('processing_status')->all());
    }

    public function test_nominal_yang_tidak_cocok_ditolak_dan_dicatat(): void
    {
        [,, $grup, $p] = $this->siap();

        // Tanda tangan benar untuk nominal yang salah: hanya nominal yang membuatnya ditolak.
        $this->terima($this->notifikasi($p->midtrans_order_id, $this->nominal($p), 'settlement', ['gross_amount' => '1.00']))->assertOk();

        $this->assertSame('pending', $p->fresh()->status);
        $this->assertSame('pending_payment', $grup->fresh()->status);

        $log = WebhookLog::first();

        $this->assertSame('failed', $log->processing_status);
        $this->assertStringContainsString('Nominal', $log->error);
    }

    public function test_kedaluwarsa_menutup_checkout_mengembalikan_stok_dan_melepas_voucher(): void
    {
        [,, $grup, $p] = $this->siap();

        $grup->forceFill(['payment_expires_at' => now()->subMinute()])->save();

        $this->terima($this->notifikasi($p->midtrans_order_id, $this->nominal($p), 'expire'))->assertOk();

        $this->assertSame('expired', $p->fresh()->status);
        $this->assertSame('expired', $grup->fresh()->status);
        $this->assertSame('cancelled', Order::first()->status);
        $this->assertSame(10, (int) ProductVariant::first()->stock);
        $this->assertSame('released', VoucherUsage::first()->status);
        $this->assertSame(0, (int) Voucher::first()->used_count);
    }

    public function test_notifikasi_percobaan_lama_diabaikan_dan_checkout_tetap_terbuka(): void
    {
        [, $token, $grup, $p1] = $this->siap();

        $this->bayar($grup, $token, 'bank_transfer', 'bni')->assertStatus(201);
        $p2 = Payment::orderByDesc('id')->first();

        // Percobaan 1 sudah dibatalkan lokal: notifikasi kedaluwarsanya tidak mengubah apa pun.
        $this->terima($this->notifikasi($p1->midtrans_order_id, $this->nominal($p1), 'expire'))
            ->assertOk()->assertJson(['message' => 'Notifikasi sudah pernah diproses.']);

        $this->assertSame('cancelled', $p1->fresh()->status);
        $this->assertSame('pending_payment', $grup->fresh()->status);

        // Percobaan 2 kedaluwarsa sebelum batas bayar checkout: checkout tetap terbuka dan stok tertahan.
        $this->terima($this->notifikasi($p2->midtrans_order_id, $this->nominal($p2), 'expire'))->assertOk();

        $this->assertSame('expired', $p2->fresh()->status);
        $this->assertSame('pending_payment', $grup->fresh()->status);
        $this->assertSame(8, (int) ProductVariant::first()->stock);
    }

    public function test_pembayaran_ditolak_tidak_menutup_checkout_dan_bisa_dicoba_lagi(): void
    {
        [, $token, $grup, $p] = $this->siap();

        $this->terima($this->notifikasi($p->midtrans_order_id, $this->nominal($p), 'deny'))->assertOk();

        $this->assertSame('denied', $p->fresh()->status);
        $this->assertSame('pending_payment', $grup->fresh()->status);
        $this->assertSame(8, (int) ProductVariant::first()->stock);

        $this->bayar($grup, $token)->assertStatus(201)->assertJsonPath('data.attempt', 2);
    }

    public function test_dana_yang_masuk_setelah_checkout_ditutup_dicatat_untuk_pengembalian_manual(): void
    {
        [,, $grup, $p] = $this->siap();

        $grup->forceFill(['payment_expires_at' => now()->subMinute()])->save();

        $this->assertSame(1, app(PaymentService::class)->expireOverdue());
        $this->assertSame('expired', $grup->fresh()->status);
        $this->assertSame('cancelled', Order::first()->status);
        $this->assertSame(10, (int) ProductVariant::first()->stock);
        $this->assertSame('released', VoucherUsage::first()->status);

        $this->terima($this->notifikasi($p->midtrans_order_id, $this->nominal($p), 'settlement'))->assertOk();

        // Dana tercatat diterima, tetapi checkout tidak dihidupkan kembali.
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame('expired', $grup->fresh()->status);
        $this->assertSame('cancelled', Order::first()->status);

        $log = WebhookLog::orderByDesc('id')->first();

        $this->assertSame('failed', $log->processing_status);
        $this->assertStringContainsString('pengembalian dana', $log->error);
    }

    public function test_pembayaran_ganda_pada_dua_percobaan_dicatat_dan_tidak_melunasi_dua_kali(): void
    {
        [, $token, $grup, $p1] = $this->siap();

        $this->bayar($grup, $token, 'bank_transfer', 'bni')->assertStatus(201);
        $p2 = Payment::orderByDesc('id')->first();

        $this->terima($this->notifikasi($p2->midtrans_order_id, $this->nominal($p2), 'settlement'))->assertOk();

        $this->assertSame('paid', $grup->fresh()->status);

        $this->terima($this->notifikasi($p1->midtrans_order_id, $this->nominal($p1), 'settlement'))->assertOk();

        $this->assertSame('paid', $p1->fresh()->status);
        $this->assertSame('paid', $grup->fresh()->status);
        $this->assertSame('paid', Order::first()->status);

        $log = WebhookLog::orderByDesc('id')->first();

        $this->assertSame('failed', $log->processing_status);
        $this->assertStringContainsString('ganda', $log->error);
    }

    public function test_notifikasi_untuk_pembayaran_yang_tidak_dikenal_diabaikan(): void
    {
        $this->terima($this->notifikasi('TIDAK-ADA-1', 100000, 'settlement'))
            ->assertOk()->assertJson(['message' => 'Pembayaran tidak dikenal.']);

        $log = WebhookLog::first();

        $this->assertSame('ignored', $log->processing_status);
        $this->assertTrue((bool) $log->is_valid);
        $this->assertSame('TIDAK-ADA-1', $log->external_id);
    }

    public function test_hanya_checkout_yang_melewati_batas_bayar_yang_ditutup(): void
    {
        [$u1] = $this->pembeli();
        [$u2] = $this->pembeli();

        $lewat   = $this->checkout($u1, ['batas' => now()->subHour()]);
        $berlaku = $this->checkout($u2);

        $layanan = app(PaymentService::class);

        $this->assertSame(1, $layanan->expireOverdue());
        $this->assertSame('expired', $lewat->fresh()->status);
        $this->assertSame('pending_payment', $berlaku->fresh()->status);
        $this->assertSame('cancelled', Order::where('checkout_group_id', $lewat->id)->value('status'));
        $this->assertSame('pending_payment', Order::where('checkout_group_id', $berlaku->id)->value('status'));

        $this->assertSame(0, $layanan->expireOverdue());
    }

    public function test_perintah_simulasi_melunasi_checkout_dan_menolak_berjalan_di_production(): void
    {
        [,, $grup, $p] = $this->siap();

        $this->app['env'] = 'production';

        try {
            $this->artisan('nexora:simulate-payment', ['order_id' => $p->midtrans_order_id, 'status' => 'settlement'])->assertExitCode(1);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame('pending_payment', $grup->fresh()->status);

        $this->artisan('nexora:simulate-payment', ['order_id' => $p->midtrans_order_id, 'status' => 'settlement'])->assertExitCode(0);

        $this->assertSame('paid', $grup->fresh()->status);
        $this->assertSame('paid', Order::first()->status);
    }
}
