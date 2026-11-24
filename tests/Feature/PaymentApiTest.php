<?php

namespace Tests\Feature;

use App\Models\CheckoutGroup;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SiapkanPembayaran;
use Tests\TestCase;

/**
 * Menguji pembuatan tagihan pembayaran (VA bank dan QRIS) lewat HTTP. Midtrans dipalsukan.
 */
class PaymentApiTest extends TestCase
{
    use RefreshDatabase;
    use SiapkanPembayaran;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturMidtrans();
    }

    public function test_pembayaran_butuh_login_dan_akun_aktif(): void
    {
        Http::fake();

        $rute = [
            ['POST', '/api/v1/checkout/' . $this->uuidAsing() . '/pay'],
            ['GET', '/api/v1/checkout/' . $this->uuidAsing() . '/payment'],
        ];

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, ['method' => 'qris'])->assertStatus(401);
        }

        [, $token] = $this->pembeli('suspended');

        foreach ($rute as [$method, $uri]) {
            $this->kirim($method, $uri, ['method' => 'qris'], $token)->assertStatus(403);
        }

        Http::assertNothingSent();
    }

    public function test_pembayaran_menolak_masukan_tidak_valid(): void
    {
        Http::fake();
        [, $token] = $this->pembeli();

        $kasus = [
            [[], 'method'],
            [['method' => 'cash'], 'method'],
            [['method' => 'bank_transfer'], 'bank'],
            [['method' => 'bank_transfer', 'bank' => 'mandiri'], 'bank'],
            [['method' => 'qris', 'bank' => 'bca'], 'bank'],
        ];

        foreach ($kasus as [$data, $kolom]) {
            $this->kirim('POST', '/api/v1/checkout/' . $this->uuidAsing() . '/pay', $data, $token)
                ->assertStatus(422)->assertJson(['success' => false])->assertJsonValidationErrors([$kolom]);
        }

        Http::assertNothingSent();
    }

    public function test_tagihan_va_bca_dibuat_dengan_jumlah_dan_batas_waktu_yang_benar(): void
    {
        [$user, $token] = $this->pembeli();
        $grup           = $this->checkout($user);
        $this->palsuMidtrans();

        $res = $this->bayar($grup, $token)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Pembayaran berhasil dibuat.'])
            ->assertJsonPath('data.method', 'bank_transfer')
            ->assertJsonPath('data.bank', 'bca')
            ->assertJsonPath('data.va_number', '1234567890')
            ->assertJsonPath('data.gross_amount', 211000)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.attempt', 1)
            ->assertJsonPath('data.qr_string', null)
            ->assertJsonMissingPath('data.midtrans_order_id');

        $this->assertStringNotContainsString($this->kunciServer(), $res->getContent());
        $this->assertStringNotContainsString('G000000', $res->getContent());

        $p = Payment::first();

        $this->assertSame(1, Payment::count());
        $this->assertSame($grup->number . '-1', $p->midtrans_order_id);
        $this->assertSame('pending', $p->status);
        $this->assertSame(211000, (int) $p->gross_amount);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $p->expires_at->timestamp, 120);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) use ($user, $grup) {
            return str_ends_with($r->url(), '/v2/charge')
                && $r->hasHeader('Authorization', 'Basic ' . base64_encode($this->kunciServer() . ':'))
                && $r['payment_type'] === 'bank_transfer'
                && $r['bank_transfer']['bank'] === 'bca'
                && $r['transaction_details']['order_id'] === $grup->number . '-1'
                && $r['transaction_details']['gross_amount'] === 211000
                && array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $r['item_details'])) === 211000
                && $r['custom_expiry']['unit'] === 'minute'
                && $r['custom_expiry']['expiry_duration'] >= 1439
                && $r['custom_expiry']['expiry_duration'] <= 1440
                && $r['customer_details']['email'] === $user->email;
        });
    }

    public function test_permintaan_sama_mengembalikan_tagihan_lama_dan_ganti_bank_membuat_percobaan_baru(): void
    {
        [$user, $token] = $this->pembeli();
        $grup           = $this->checkout($user);
        $this->palsuMidtrans();

        $a = $this->bayar($grup, $token)->assertStatus(201);

        $this->bayar($grup, $token)
            ->assertOk()
            ->assertJson(['message' => 'Pembayaran yang masih berlaku dikembalikan.'])
            ->assertJsonPath('data.id', $a->json('data.id'));

        $this->assertSame(1, Payment::count());
        Http::assertSentCount(1);

        $b = $this->bayar($grup, $token, 'bank_transfer', 'bni')
            ->assertStatus(201)
            ->assertJsonPath('data.attempt', 2)
            ->assertJsonPath('data.bank', 'bni');

        $this->assertNotSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(['cancelled', 'pending'], Payment::orderBy('attempt')->pluck('status')->all());

        // Percobaan lama diminta kedaluwarsa di Midtrans: dua tagihan dan satu permintaan kedaluwarsa.
        Http::assertSentCount(3);
        Http::assertSent(fn(Request $r) => str_ends_with($r->url(), '/' . $grup->number . '-1/expire'));
    }

    public function test_tagihan_permata_dan_qris(): void
    {
        [$user, $token] = $this->pembeli();
        $grup           = $this->checkout($user);
        $this->palsuMidtrans();

        $this->bayar($grup, $token, 'bank_transfer', 'permata')
            ->assertStatus(201)
            ->assertJsonPath('data.bank', 'permata')
            ->assertJsonPath('data.va_number', '8778000002332015');

        $q = $this->bayar($grup, $token, 'qris', null)
            ->assertStatus(201)
            ->assertJsonPath('data.method', 'qris')
            ->assertJsonPath('data.attempt', 2)
            ->assertJsonPath('data.bank', null)
            ->assertJsonPath('data.va_number', null)
            ->assertJsonPath('data.qr_string', '00020101QRSTRING');

        $this->assertStringContainsString('/qr-code', $q->json('data.qr_url'));

        // QRIS berlaku 60 menit, bukan 24 jam.
        $sisa = $q->json('data.expires_in_seconds');

        $this->assertGreaterThan(3500, $sisa);
        $this->assertLessThanOrEqual(3600, $sisa);

        Http::assertSent(fn(Request $r) => str_ends_with($r->url(), '/v2/charge')
            && $r['payment_type'] === 'qris'
            && $r['qris']['acquirer'] === 'gopay'
            && $r['custom_expiry']['expiry_duration'] === 60);
    }

    public function test_pembayaran_ditolak_untuk_metode_nonaktif_checkout_tertutup_dan_milik_orang_lain(): void
    {
        [$user, $token] = $this->pembeli();
        $grup           = $this->checkout($user);
        Http::fake();

        Setting::put('payment.allowed_types', ['bank_transfer']);
        $this->bayar($grup, $token, 'qris', null)
            ->assertStatus(422)->assertJsonPath('errors.method.0', PaymentService::PESAN_METODE);
        Setting::put('payment.allowed_types', ['bank_transfer', 'qris']);

        $grup->forceFill(['status' => CheckoutGroup::STATUS_PAID])->save();
        $this->bayar($grup, $token)
            ->assertStatus(422)->assertJsonPath('errors.checkout.0', PaymentService::PESAN_BUKAN_TUNGGU);

        $grup->forceFill(['status' => CheckoutGroup::STATUS_PENDING_PAYMENT, 'payment_expires_at' => now()->subMinute()])->save();
        $this->bayar($grup, $token)
            ->assertStatus(422)->assertJsonPath('errors.checkout.0', PaymentService::PESAN_LEWAT_BATAS);

        [, $tokenLain] = $this->pembeli();
        $this->bayar($grup, $tokenLain)->assertStatus(404);

        $this->assertSame(0, Payment::count());
        Http::assertNothingSent();
    }

    public function test_percobaan_pembayaran_dibatasi_lima_per_checkout(): void
    {
        [$user, $token] = $this->pembeli();
        $grup           = $this->checkout($user);
        $this->palsuMidtrans();

        foreach ([['bank_transfer', 'bca'], ['bank_transfer', 'bni'], ['bank_transfer', 'bri'], ['bank_transfer', 'permata'], ['qris', null]] as [$metode, $bank]) {
            $this->bayar($grup, $token, $metode, $bank)->assertStatus(201);
        }

        $this->bayar($grup, $token, 'bank_transfer', 'bca')
            ->assertStatus(422)->assertJsonPath('errors.checkout.0', PaymentService::PESAN_TERLALU_BANYAK);

        $this->assertSame(5, Payment::count());
        $this->assertSame(['cancelled', 'cancelled', 'cancelled', 'cancelled', 'pending'], Payment::orderBy('attempt')->pluck('status')->all());
    }

    public function test_kegagalan_midtrans_dijawab_aman_dan_tidak_meninggalkan_tagihan(): void
    {
        [$user, $token] = $this->pembeli();
        $grup           = $this->checkout($user);

        Http::fake(['api.sandbox.midtrans.com/v2/charge' => Http::sequence()
            ->push(['status_code' => '500', 'status_message' => 'rahasia internal'], 500)
            ->push(['status_code' => '406', 'status_message' => 'rahasia duplikat'], 200)
            ->push(['status_code' => '402', 'status_message' => 'rahasia kanal'], 200)]);

        foreach ([503, 409, 503] as $status) {
            $res = $this->bayar($grup, $token)->assertStatus($status)->assertJson(['success' => false]);

            $this->assertStringNotContainsString('rahasia', $res->getContent());
            $this->assertStringNotContainsString($this->kunciServer(), $res->getContent());
        }

        $this->assertSame(0, Payment::count());
        Http::assertSentCount(3);
    }

    public function test_tanpa_kunci_server_langsung_gagal_tanpa_memanggil_midtrans(): void
    {
        [$user, $token] = $this->pembeli();
        $grup           = $this->checkout($user);

        config(['midtrans.server_key' => null]);
        Http::fake();

        $this->bayar($grup, $token)
            ->assertStatus(503)
            ->assertJson(['success' => false, 'message' => 'Layanan pembayaran sedang tidak tersedia. Coba lagi beberapa saat.']);

        $this->assertSame(0, Payment::count());
        Http::assertNothingSent();
    }

    public function test_pembeli_melihat_pembayaran_terbaru_miliknya_sendiri(): void
    {
        [$user, $token] = $this->pembeli();
        $grup           = $this->checkout($user);
        $url            = "/api/v1/checkout/{$grup->uuid}/payment";

        $this->kirim('GET', $url, [], $token)->assertStatus(404);

        $this->palsuMidtrans();
        $this->bayar($grup, $token)->assertStatus(201);
        $b = $this->bayar($grup, $token, 'bank_transfer', 'bni')->assertStatus(201);

        $this->kirim('GET', $url, [], $token)
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.id', $b->json('data.id'))
            ->assertJsonPath('data.attempt', 2);

        [, $tokenLain] = $this->pembeli();
        $this->kirim('GET', $url, [], $tokenLain)->assertStatus(404);
        $this->kirim('GET', '/api/v1/checkout/' . $this->uuidAsing() . '/payment', [], $token)->assertStatus(404);
    }
}
