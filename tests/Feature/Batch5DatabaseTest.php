<?php

namespace Tests\Feature;

use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentTrackingHistory;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menguji tabel dan fungsi Batch 5 (pembayaran Midtrans Core API dan pengiriman Biteship).
 * Berjalan di SQLite memori, tidak menyentuh MySQL dan tidak menghubungi Midtrans/Biteship.
 */
class Batch5DatabaseTest extends TestCase
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

    private function buatGrup(): CheckoutGroup
    {
        return CheckoutGroup::create([
            'user_id'        => $this->buatUser()->id,
            'number'         => CheckoutGroup::generateNumber(),
            'subtotal'       => 100000,
            'shipping_total' => 5000,
            'grand_total'    => 105000,
        ]);
    }

    private function buatPesanan(): Order
    {
        $grup = $this->buatGrup();

        return Order::create([
            'checkout_group_id' => $grup->id,
            'user_id'           => $grup->user_id,
            'store_id'          => $this->buatToko()->id,
            'number'            => Order::generateNumber($grup, 1),
            'shipping_address'  => ['city' => 'Sleman'],
            'subtotal'          => 100000,
            'shipping_cost'     => 5000,
            'grand_total'       => 105000,
        ])->fresh();
    }

    private function buatPembayaran(CheckoutGroup $grup, int $attempt = 1): Payment
    {
        return Payment::create([
            'checkout_group_id' => $grup->id,
            'attempt'           => $attempt,
            'midtrans_order_id' => $grup->number . '-' . $attempt,
            'payment_type'      => 'bank_transfer',
            'bank'              => 'bca',
            'gross_amount'      => 105000,
            'expires_at'        => now()->addDay(),
        ]);
    }

    public function test_pembayaran_punya_default_dan_relasi(): void
    {
        $grup       = $this->buatGrup();
        $pembayaran = $this->buatPembayaran($grup)->fresh();

        $this->assertSame(36, strlen($pembayaran->uuid));
        $this->assertSame(Payment::STATUS_PENDING, $pembayaran->status);
        $this->assertNull($pembayaran->paid_at);
        $this->assertSame($grup->number . '-1', $pembayaran->midtrans_order_id);
        $this->assertLessThanOrEqual(50, strlen($pembayaran->midtrans_order_id));
        $this->assertSame($grup->id, $pembayaran->checkoutGroup->id);
        $this->assertSame(1, $grup->payments()->count());
    }

    public function test_nomor_percobaan_bayar_unik_per_grup_tetapi_boleh_bertambah(): void
    {
        $grup = $this->buatGrup();

        $this->buatPembayaran($grup, 1);
        $this->buatPembayaran($grup, 2);   // ganti bank: percobaan baru, boleh

        $this->assertSame(2, $grup->payments()->count());

        $this->expectException(QueryException::class);
        Payment::create([
            'checkout_group_id' => $grup->id,
            'attempt'           => 2,
            'midtrans_order_id' => 'LAIN-2',
            'payment_type'      => 'bank_transfer',
            'gross_amount'      => 105000,
        ]);
    }

    public function test_status_pembayaran_tidak_bisa_diisi_massal(): void
    {
        $grup = $this->buatGrup();

        $pembayaran = Payment::create([
            'checkout_group_id' => $grup->id,
            'midtrans_order_id' => $grup->number . '-1',
            'payment_type'      => 'bank_transfer',
            'gross_amount'      => 105000,
            // Harus diabaikan oleh mass assignment:
            'status'  => 'paid',
            'paid_at' => now(),
        ])->fresh();

        $this->assertSame(Payment::STATUS_PENDING, $pembayaran->status);
        $this->assertNull($pembayaran->paid_at);
    }

    public function test_pemetaan_status_midtrans(): void
    {
        $this->assertSame('paid', Payment::mapMidtransStatus('settlement'));
        $this->assertSame('paid', Payment::mapMidtransStatus('capture'));
        $this->assertSame('paid', Payment::mapMidtransStatus('capture', 'accept'));
        $this->assertSame('paid', Payment::mapMidtransStatus('capture', ''));
        $this->assertSame('pending', Payment::mapMidtransStatus('capture', 'challenge'));
        $this->assertSame('denied', Payment::mapMidtransStatus('capture', 'deny'));
        $this->assertSame('pending', Payment::mapMidtransStatus('pending'));
        $this->assertSame('expired', Payment::mapMidtransStatus('expire'));
        $this->assertSame('cancelled', Payment::mapMidtransStatus('cancel'));
        $this->assertSame('denied', Payment::mapMidtransStatus('deny'));
        $this->assertSame('failed', Payment::mapMidtransStatus('failure'));

        // Status yang belum kita tangani: null (harus diabaikan).
        $this->assertNull(Payment::mapMidtransStatus('refund'));
        $this->assertNull(Payment::mapMidtransStatus('apa-saja'));
    }

    public function test_signature_midtrans_valid_dan_manipulasi_ditolak(): void
    {
        $serverKey = 'kunci-contoh-bukan-kunci-asli';

        $payload = [
            'order_id'     => 'NXR-261116093045-K7QZ2-1',
            'status_code'  => '200',
            'gross_amount' => '105000.00',
        ];
        $payload['signature_key'] = hash('sha512', 'NXR-261116093045-K7QZ2-1' . '200' . '105000.00' . $serverKey);

        $this->assertTrue(Payment::isValidSignature($payload, $serverKey));

        // Nominal diubah penyerang.
        $this->assertFalse(Payment::isValidSignature(array_merge($payload, ['gross_amount' => '1000.00']), $serverKey));
        // Status diubah penyerang.
        $this->assertFalse(Payment::isValidSignature(array_merge($payload, ['status_code' => '201']), $serverKey));
        // order_id diubah.
        $this->assertFalse(Payment::isValidSignature(array_merge($payload, ['order_id' => 'LAIN-1']), $serverKey));
        // Server key salah atau kosong.
        $this->assertFalse(Payment::isValidSignature($payload, 'kunci-lain'));
        $this->assertFalse(Payment::isValidSignature($payload, ''));
        // Signature hilang atau kolom wajib hilang.
        unset($payload['signature_key']);
        $this->assertFalse(Payment::isValidSignature($payload, $serverKey));
        $this->assertFalse(Payment::isValidSignature(['signature_key' => 'x'], $serverKey));
    }

    public function test_nominal_notifikasi_dicocokkan_dengan_nominal_tersimpan(): void
    {
        $pembayaran = $this->buatPembayaran($this->buatGrup());

        $this->assertTrue($pembayaran->grossAmountMatches(['gross_amount' => '105000.00']));
        $this->assertTrue($pembayaran->grossAmountMatches(['gross_amount' => 105000]));
        $this->assertFalse($pembayaran->grossAmountMatches(['gross_amount' => '1000.00']));
        $this->assertFalse($pembayaran->grossAmountMatches(['gross_amount' => 'abc']));
        $this->assertFalse($pembayaran->grossAmountMatches([]));
    }

    public function test_checkout_yang_punya_pembayaran_tidak_bisa_dihapus(): void
    {
        $grup = $this->buatGrup();
        $this->buatPembayaran($grup);

        $this->expectException(QueryException::class);
        $grup->delete();
    }

    public function test_pengiriman_punya_default_relasi_dan_satu_per_pesanan(): void
    {
        $pesanan = $this->buatPesanan();

        $pengiriman = $pesanan->shipment()->create([
            'biteship_order_id' => '5dd6da88f43bd430ecd5aa2e',
            'courier_company'   => 'jne',
            'courier_type'      => 'reg',
            'status'            => 'delivered',   // harus diabaikan
        ])->fresh();

        $this->assertSame(36, strlen($pengiriman->uuid));
        $this->assertSame(Shipment::STATUS_PENDING, $pengiriman->status);
        $this->assertSame($pesanan->id, $pengiriman->order->id);
        $this->assertSame($pengiriman->id, $pesanan->fresh()->shipment->id);

        $this->expectException(QueryException::class);
        $pesanan->shipment()->create(['biteship_order_id' => 'order-lain']);
    }

    public function test_id_order_biteship_unik_antar_pesanan(): void
    {
        $this->buatPesanan()->shipment()->create(['biteship_order_id' => 'abc123']);

        $this->expectException(QueryException::class);
        $this->buatPesanan()->shipment()->create(['biteship_order_id' => 'abc123']);
    }

    public function test_normalisasi_status_biteship(): void
    {
        $this->assertSame('picking_up', Shipment::normalizeStatus('pickingUp'));
        $this->assertSame('picking_up', Shipment::normalizeStatus('picking_up'));
        $this->assertSame('picking_up', Shipment::normalizeStatus('PICKING_UP'));
        $this->assertSame('in_transit', Shipment::normalizeStatus('inTransit'));
        $this->assertSame('dropping_off', Shipment::normalizeStatus('droppingOff'));
        $this->assertSame('return_in_transit', Shipment::normalizeStatus('returnInTransit'));
        $this->assertSame('courier_not_found', Shipment::normalizeStatus('courierNotFound'));
        $this->assertSame('on_hold', Shipment::normalizeStatus('onHold'));
        $this->assertSame('delivered', Shipment::normalizeStatus('delivered'));
        $this->assertSame('picked', Shipment::normalizeStatus(' PICKED '));
    }

    public function test_status_pengiriman_dicatat_sekali_walau_webhook_dikirim_ulang(): void
    {
        $pengiriman = $this->buatPesanan()->shipment()->create(['biteship_order_id' => 'abc123']);

        $this->assertTrue($pengiriman->applyStatus('confirmed', ['status' => 'confirmed']));
        $this->assertFalse($pengiriman->applyStatus('confirmed'));       // dikirim ulang
        $this->assertTrue($pengiriman->applyStatus('pickingUp'));
        $this->assertFalse($pengiriman->applyStatus('picking_up'));      // gaya penulisan lain, status sama

        $this->assertNull($pengiriman->picked_at);
        $this->assertTrue($pengiriman->applyStatus('picked'));
        $this->assertNotNull($pengiriman->picked_at);

        $this->assertNull($pengiriman->delivered_at);
        $this->assertTrue($pengiriman->applyStatus('delivered'));
        $this->assertNotNull($pengiriman->delivered_at);

        $this->assertSame('delivered', $pengiriman->fresh()->status);
        $this->assertSame(
            ['confirmed', 'picking_up', 'picked', 'delivered'],
            $pengiriman->trackingHistories()->pluck('status')->all()
        );
        $this->assertSame(['status' => 'confirmed'], $pengiriman->trackingHistories()->first()->payload);
    }

    public function test_webhook_log_menyimpan_payload_dan_nilai_awal(): void
    {
        $log = WebhookLog::create([
            'provider'    => WebhookLog::PROVIDER_MIDTRANS,
            'event'       => 'payment.notification',
            'external_id' => 'NXR-1-1',
            'payload'     => ['transaction_status' => 'settlement', 'gross_amount' => '105000.00'],
            'is_valid'    => false,
            'ip_address'  => '127.0.0.1',
        ])->fresh();

        $this->assertSame(WebhookLog::STATUS_RECEIVED, $log->processing_status);
        $this->assertFalse($log->is_valid);
        $this->assertSame('settlement', $log->payload['transaction_status']);
        $this->assertNotNull($log->created_at);
        $this->assertNull($log->processed_at);
    }

    public function test_hapus_pesanan_menghapus_pengiriman_dan_riwayatnya(): void
    {
        $pesanan    = $this->buatPesanan();
        $pengiriman = $pesanan->shipment()->create(['biteship_order_id' => 'abc123']);
        $pengiriman->applyStatus('confirmed');

        $pesanan->delete();

        $this->assertSame(0, Shipment::count());
        $this->assertSame(0, ShipmentTrackingHistory::count());
    }
}
