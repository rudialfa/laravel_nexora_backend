<?php

namespace App\Services;

use App\Exceptions\MidtransException;
use App\Models\CheckoutGroup;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Models\VoucherUsage;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pembayaran checkout lewat Midtrans (virtual account bank dan QRIS) dan pengolahan notifikasinya.
 */
class PaymentService
{
    public const METHOD_BANK = 'bank_transfer';
    public const METHOD_QRIS = 'qris';

    public const PESAN_METODE         = 'Metode pembayaran tidak tersedia.';
    public const PESAN_BUKAN_TUNGGU   = 'Checkout ini sudah tidak menunggu pembayaran.';
    public const PESAN_LEWAT_BATAS    = 'Batas waktu pembayaran checkout ini sudah lewat.';
    public const PESAN_TERLALU_BANYAK = 'Terlalu banyak percobaan pembayaran untuk checkout ini.';
    public const PESAN_TOTAL_NOL      = 'Total pembayaran tidak valid.';

    private const PESAN_TIDAK_TERSEDIA = 'Layanan pembayaran sedang tidak tersedia. Coba lagi beberapa saat.';

    public function __construct(private readonly MidtransService $midtrans) {}

    // ------------------------------------------------------------- PEMBELI

    /**
     * Membuat tagihan untuk checkout. Tagihan yang sama dan masih berlaku dikembalikan apa adanya.
     *
     * @param  array<string, mixed>  $data
     * @return array{payment: Payment, created: bool}
     *
     * @throws ValidationException
     * @throws MidtransException
     */
    public function pay(User $user, string $groupUuid, array $data): array
    {
        $metode = $data['method'];
        $bank   = $metode === self::METHOD_BANK ? strtolower((string) $data['bank']) : null;

        if (! in_array($metode, (array) Setting::getValue('payment.allowed_types', [self::METHOD_BANK]), true)) {
            $this->reject('method', self::PESAN_METODE);
        }

        $lama = [];

        $hasil = DB::transaction(function () use ($user, $groupUuid, $metode, $bank, &$lama) {
            // Satu checkout diproses satu per satu agar dua permintaan tidak membuat tagihan kembar.
            $grup = CheckoutGroup::query()
                ->where('user_id', $user->id)
                ->where('uuid', $groupUuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($grup->status !== CheckoutGroup::STATUS_PENDING_PAYMENT) {
                $this->reject('checkout', self::PESAN_BUKAN_TUNGGU);
            }

            if ($grup->payment_expires_at === null || $grup->payment_expires_at->lte(now())) {
                $this->reject('checkout', self::PESAN_LEWAT_BATAS);
            }

            if ((int) $grup->grand_total <= 0) {
                $this->reject('checkout', self::PESAN_TOTAL_NOL);
            }

            $percobaan = Payment::query()->where('checkout_group_id', $grup->id)->orderBy('attempt')->get();

            $aktif = $percobaan->first(fn(Payment $p) => $p->status === Payment::STATUS_PENDING
                && $p->payment_type === $metode
                && $p->bank === $bank
                && ($p->expires_at === null || $p->expires_at->gt(now())));

            if ($aktif !== null) {
                return ['payment' => $aktif, 'created' => false];
            }

            if ($percobaan->count() >= (int) config('midtrans.max_attempts', 5)) {
                $this->reject('checkout', self::PESAN_TERLALU_BANYAK);
            }

            foreach ($percobaan->where('status', Payment::STATUS_PENDING) as $sebelumnya) {
                $sebelumnya->forceFill(['status' => Payment::STATUS_CANCELLED])->save();
                $lama[] = $sebelumnya->midtrans_order_id;
            }

            $nomor = (int) $percobaan->max('attempt') + 1;
            $order = $grup->number . '-' . $nomor;

            $sisaMenit = max(1, (int) ceil(now()->diffInSeconds($grup->payment_expires_at, false) / 60));
            $menit     = $metode === self::METHOD_QRIS ? min($sisaMenit, (int) config('midtrans.qris_expiry_minutes', 60)) : $sisaMenit;

            $payload = [
                'payment_type'        => $metode,
                'transaction_details' => ['order_id' => $order, 'gross_amount' => (int) $grup->grand_total],
                'item_details'        => $this->rincian($grup),
                'customer_details'    => ['first_name' => Str::limit((string) $user->name, 100, ''), 'email' => $user->email],
                'custom_expiry'       => ['order_time' => now()->format('Y-m-d H:i:s O'), 'expiry_duration' => $menit, 'unit' => 'minute'],
            ];

            if ($metode === self::METHOD_BANK) {
                $payload['bank_transfer'] = ['bank' => $bank];
            } else {
                $payload['qris'] = ['acquirer' => (string) config('midtrans.qris_acquirer', 'gopay')];
            }

            // Kegagalan Midtrans melempar pengecualian, sehingga seluruh perubahan di atas dibatalkan.
            $res = $this->midtrans->charge($payload);

            $vaNumber = null;
            $detail   = [];

            if ($metode === self::METHOD_BANK) {
                $vaNumber = $bank === 'permata'
                    ? ($res['permata_va_number'] ?? null)
                    : ($res['va_numbers'][0]['va_number'] ?? null);

                if (blank($vaNumber)) {
                    throw new MidtransException(self::PESAN_TIDAK_TERSEDIA, 503, 'unavailable');
                }

                $detail = ['expiry_time' => $res['expiry_time'] ?? null];
            } else {
                $qrUrl    = collect($res['actions'] ?? [])->firstWhere('name', 'generate-qr-code')['url'] ?? null;
                $qrString = $res['qr_string'] ?? null;

                if (blank($qrUrl) && blank($qrString)) {
                    throw new MidtransException(self::PESAN_TIDAK_TERSEDIA, 503, 'unavailable');
                }

                $detail = ['qr_string' => $qrString, 'qr_url' => $qrUrl, 'acquirer' => $res['acquirer'] ?? config('midtrans.qris_acquirer')];
            }

            $payment = new Payment;
            $payment->forceFill([
                'checkout_group_id'       => $grup->id,
                'attempt'                 => $nomor,
                'midtrans_order_id'       => $order,
                'midtrans_transaction_id' => $res['transaction_id'] ?? null,
                'payment_type'            => $metode,
                'bank'                    => $bank,
                'va_number'               => $vaNumber,
                'payment_details'         => $detail,
                'gross_amount'            => (int) $grup->grand_total,
                'status'                  => Payment::STATUS_PENDING,
                'transaction_status'      => $res['transaction_status'] ?? 'pending',
                'fraud_status'            => $res['fraud_status'] ?? null,
                'status_code'             => (string) ($res['status_code'] ?? ''),
                'expires_at'              => now()->addMinutes($menit),
            ])->save();

            return ['payment' => $payment->fresh(), 'created' => true];
        });

        foreach ($lama as $orderId) {
            $this->midtrans->expire($orderId);
        }

        return $hasil;
    }

    /** Percobaan pembayaran terbaru milik pengguna ini. Milik orang lain atau belum ada: 404. */
    public function latest(User $user, string $groupUuid): Payment
    {
        $grup = CheckoutGroup::query()->where('user_id', $user->id)->where('uuid', $groupUuid)->firstOrFail();

        return Payment::query()->where('checkout_group_id', $grup->id)->orderByDesc('attempt')->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function present(Payment $p): array
    {
        $d        = (array) $p->payment_details;
        $menunggu = $p->status === Payment::STATUS_PENDING && $p->expires_at !== null;

        return [
            'id'                 => $p->uuid,
            'attempt'            => (int) $p->attempt,
            'method'             => $p->payment_type,
            'bank'               => $p->bank,
            'va_number'          => $p->va_number,
            'qr_string'          => $d['qr_string'] ?? null,
            'qr_url'             => $d['qr_url'] ?? null,
            'gross_amount'       => (int) $p->gross_amount,
            'status'             => $p->status,
            'expires_at'         => $p->expires_at?->toIso8601String(),
            'expires_in_seconds' => $menunggu ? max(0, (int) floor(now()->diffInSeconds($p->expires_at, false))) : 0,
            'paid_at'            => $p->paid_at?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------- WEBHOOK

    /**
     * Mengolah notifikasi Midtrans. Selalu mencatat ke log webhook.
     *
     * @param  array<string, mixed>  $payload
     * @return array{http: int, status: string, message: string}
     */
    public function handleNotification(array $payload, ?string $ip): array
    {
        $orderId = is_string($payload['order_id'] ?? null) ? $payload['order_id'] : null;

        $log = new WebhookLog;
        $log->forceFill([
            'provider'          => WebhookLog::PROVIDER_MIDTRANS,
            'event'             => is_string($payload['transaction_status'] ?? null) ? Str::limit($payload['transaction_status'], 50, '') : null,
            'external_id'       => $orderId === null ? null : Str::limit($orderId, 100, ''),
            'payload'           => $payload,
            'ip_address'        => $ip,
            'processing_status' => WebhookLog::STATUS_RECEIVED,
        ])->save();

        $selesai = function (string $status, string $pesan, int $http, ?bool $valid = null) use ($log) {
            $log->forceFill([
                'processing_status' => $status,
                'error'             => $status === WebhookLog::STATUS_FAILED ? $pesan : null,
                'is_valid'          => $valid ?? $log->is_valid,
                'processed_at'      => now(),
            ])->save();

            return ['http' => $http, 'status' => $status, 'message' => $pesan];
        };

        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_status'] as $kolom) {
            if (! is_string($payload[$kolom] ?? null) || $payload[$kolom] === '') {
                return $selesai(WebhookLog::STATUS_FAILED, 'Payload notifikasi tidak lengkap.', 400, false);
            }
        }

        $kunci = (string) config('midtrans.server_key');

        if ($kunci === '') {
            return $selesai(WebhookLog::STATUS_FAILED, 'Kunci server pembayaran belum dikonfigurasi.', 503);
        }

        if (! Payment::isValidSignature($payload, $kunci)) {
            return $selesai(WebhookLog::STATUS_FAILED, 'Signature tidak valid.', 403, false);
        }

        $payment = Payment::query()->where('midtrans_order_id', $payload['order_id'])->first();

        if ($payment === null) {
            return $selesai(WebhookLog::STATUS_IGNORED, 'Pembayaran tidak dikenal.', 200, true);
        }

        if (! $payment->grossAmountMatches($payload)) {
            return $selesai(WebhookLog::STATUS_FAILED, 'Nominal notifikasi tidak cocok dengan tagihan.', 200, false);
        }

        $baru = Payment::mapMidtransStatus($payload['transaction_status'], $payload['fraud_status'] ?? null);

        if ($baru === null) {
            return $selesai(WebhookLog::STATUS_IGNORED, 'Status notifikasi tidak memerlukan tindakan.', 200, true);
        }

        $hasil = DB::transaction(fn() => $this->terapkan($payment->id, $payload, $baru));

        foreach ($hasil['expire'] as $id) {
            $this->midtrans->expire($id);
        }

        return $selesai($hasil['status'], $hasil['message'], 200, true);
    }

    /**
     * Menerapkan status baru pada percobaan pembayaran dan, bila perlu, pada checkout.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, message: string, expire: array<int, string>}
     */
    private function terapkan(int $paymentId, array $payload, string $baru): array
    {
        $p = Payment::query()->lockForUpdate()->findOrFail($paymentId);
        $g = CheckoutGroup::query()->lockForUpdate()->findOrFail($p->checkout_group_id);

        $sudah = ['status' => WebhookLog::STATUS_IGNORED, 'message' => 'Notifikasi sudah pernah diproses.', 'expire' => []];

        if ($p->status === $baru || $p->status === Payment::STATUS_PAID) {
            return $sudah;
        }

        $meta = [
            'transaction_status' => $payload['transaction_status'],
            'fraud_status'       => $payload['fraud_status'] ?? null,
            'status_code'        => $payload['status_code'],
            'last_notified_at'   => now(),
        ];

        if ($baru === Payment::STATUS_PAID) {
            $p->forceFill($meta + ['status' => Payment::STATUS_PAID, 'paid_at' => now()])->save();

            if ($g->status !== CheckoutGroup::STATUS_PENDING_PAYMENT) {
                return [
                    'status'  => WebhookLog::STATUS_FAILED,
                    'message' => $g->status === CheckoutGroup::STATUS_PAID
                        ? 'Pembayaran ganda diterima untuk checkout yang sudah lunas. Perlu pengembalian dana manual.'
                        : 'Dana diterima setelah checkout ditutup. Perlu pengembalian dana manual.',
                    'expire'  => [],
                ];
            }

            return ['status' => WebhookLog::STATUS_PROCESSED, 'message' => 'Pembayaran berhasil.', 'expire' => $this->tandaiLunas($g, $p->id)];
        }

        // Percobaan yang sudah dibatalkan atau berakhir tidak diubah lagi oleh notifikasi non-bayar.
        if ($p->status !== Payment::STATUS_PENDING) {
            return $sudah;
        }

        $p->forceFill($meta + ['status' => $baru])->save();

        $adaMenunggu = Payment::query()->where('checkout_group_id', $g->id)->where('status', Payment::STATUS_PENDING)->exists();

        if (
            $g->status === CheckoutGroup::STATUS_PENDING_PAYMENT
            && ! $adaMenunggu
            && $g->payment_expires_at !== null
            && $g->payment_expires_at->lte(now()->addMinute())
        ) {
            $this->closeGroup($g, CheckoutGroup::STATUS_EXPIRED, 'Batas waktu pembayaran habis');

            return ['status' => WebhookLog::STATUS_PROCESSED, 'message' => 'Checkout ditutup karena pembayaran kedaluwarsa.', 'expire' => []];
        }

        return ['status' => WebhookLog::STATUS_PROCESSED, 'message' => 'Status pembayaran diperbarui.', 'expire' => []];
    }

    /** @return array<int, string> ID order Midtrans dari percobaan lain yang perlu diakhiri. */
    private function tandaiLunas(CheckoutGroup $g, int $paymentId): array
    {
        $g->forceFill(['status' => CheckoutGroup::STATUS_PAID, 'paid_at' => now()])->save();

        foreach ($g->orders()->orderBy('id')->get() as $order) {
            if ($order->status === Order::STATUS_PENDING_PAYMENT) {
                $order->transitionTo(Order::STATUS_PAID, Order::ACTOR_SYSTEM, null, 'Pembayaran diterima');
            }
        }

        VoucherUsage::query()
            ->where('checkout_group_id', $g->id)
            ->where('status', VoucherUsage::STATUS_RESERVED)
            ->get()
            ->each(fn(VoucherUsage $u) => $u->markUsed());

        $lain = [];

        $menunggu = Payment::query()
            ->where('checkout_group_id', $g->id)
            ->where('id', '!=', $paymentId)
            ->where('status', Payment::STATUS_PENDING)
            ->get();

        foreach ($menunggu as $p) {
            $p->forceFill(['status' => Payment::STATUS_CANCELLED])->save();
            $lain[] = $p->midtrans_order_id;
        }

        return $lain;
    }

    // ------------------------------------------------------------ PENUTUPAN

    /**
     * Menutup checkout yang belum dibayar: pesanan dibatalkan, stok dikembalikan, voucher dilepas.
     * Mengembalikan false jika checkout sudah tidak menunggu pembayaran.
     */
    public function closeGroup(CheckoutGroup $grup, string $status, string $catatan): bool
    {
        return DB::transaction(function () use ($grup, $status, $catatan) {
            $g = CheckoutGroup::query()->lockForUpdate()->findOrFail($grup->id);

            if ($g->status !== CheckoutGroup::STATUS_PENDING_PAYMENT) {
                return false;
            }

            foreach ($g->orders()->with('items')->orderBy('id')->get() as $order) {
                if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
                    continue;
                }

                $order->transitionTo(Order::STATUS_CANCELLED, Order::ACTOR_SYSTEM, null, $catatan);

                foreach ($order->items as $item) {
                    ProductVariant::query()->find($item->variant_id)?->adjustStock((int) $item->quantity, 'order_cancel', $order, null, $catatan);
                }
            }

            VoucherUsage::query()
                ->where('checkout_group_id', $g->id)
                ->where('status', VoucherUsage::STATUS_RESERVED)
                ->get()
                ->each(fn(VoucherUsage $u) => $u->release());

            foreach (Payment::query()->where('checkout_group_id', $g->id)->where('status', Payment::STATUS_PENDING)->get() as $p) {
                $p->forceFill(['status' => Payment::STATUS_CANCELLED])->save();
            }

            $g->forceFill(['status' => $status])->save();

            return true;
        });
    }

    /** Menutup semua checkout yang melewati batas bayar. Mengembalikan jumlah yang ditutup. */
    public function expireOverdue(): int
    {
        $jumlah = 0;

        CheckoutGroup::query()
            ->where('status', CheckoutGroup::STATUS_PENDING_PAYMENT)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($grup) use (&$jumlah) {
                foreach ($grup as $g) {
                    if ($this->closeGroup($g, CheckoutGroup::STATUS_EXPIRED, 'Batas waktu pembayaran habis')) {
                        $jumlah++;
                    }
                }
            });

        return $jumlah;
    }

    // ---------------------------------------------------------------- PRIVAT

    /**
     * Rincian item untuk Midtrans: satu baris per pesanan ditambah biaya layanan.
     * Jumlahnya harus sama dengan total tagihan, kalau tidak dikirim satu baris saja.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rincian(CheckoutGroup $grup): array
    {
        $baris = [];

        foreach ($grup->orders()->orderBy('id')->get() as $order) {
            if ((int) $order->grand_total > 0) {
                $baris[] = ['id' => Str::limit($order->number, 50, ''), 'price' => (int) $order->grand_total, 'quantity' => 1, 'name' => Str::limit('Pesanan ' . $order->number, 50, '')];
            }
        }

        if ((int) $grup->service_fee > 0) {
            $baris[] = ['id' => 'SERVICE_FEE', 'price' => (int) $grup->service_fee, 'quantity' => 1, 'name' => 'Biaya layanan'];
        }

        if (array_sum(array_column($baris, 'price')) !== (int) $grup->grand_total) {
            return [['id' => Str::limit($grup->number, 50, ''), 'price' => (int) $grup->grand_total, 'quantity' => 1, 'name' => Str::limit('Pembayaran ' . $grup->number, 50, '')]];
        }

        return $baris;
    }

    /** @throws ValidationException */
    private function reject(string $field, string $pesan): never
    {
        throw ValidationException::withMessages([$field => [$pesan]]);
    }
}
