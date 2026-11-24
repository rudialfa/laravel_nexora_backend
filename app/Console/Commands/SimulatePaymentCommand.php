<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Console\Command;

class SimulatePaymentCommand extends Command
{
    protected $signature = 'nexora:simulate-payment {order_id : ID order Midtrans (kolom midtrans_order_id)} {status=settlement : settlement, pending, expire, cancel, deny}';

    protected $description = 'Mensimulasikan notifikasi Midtrans bertanda tangan untuk uji manual. Hanya untuk lingkungan lokal.';

    private const KODE = ['settlement' => '200', 'pending' => '201', 'deny' => '202', 'cancel' => '200', 'expire' => '407'];

    public function handle(PaymentService $payments): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Perintah ini hanya untuk lingkungan pengembangan dan menolak berjalan di production.');

            return self::FAILURE;
        }

        $kunci  = (string) config('midtrans.server_key');
        $status = strtolower((string) $this->argument('status'));

        if ($kunci === '') {
            $this->error('MIDTRANS_SERVER_KEY belum diisi di .env.');

            return self::FAILURE;
        }

        if (! isset(self::KODE[$status])) {
            $this->error('Status harus salah satu dari: ' . implode(', ', array_keys(self::KODE)) . '.');

            return self::FAILURE;
        }

        $payment = Payment::query()->where('midtrans_order_id', (string) $this->argument('order_id'))->first();

        if ($payment === null) {
            $this->error('Pembayaran dengan order ID itu tidak ditemukan.');

            return self::FAILURE;
        }

        $kode  = self::KODE[$status];
        $total = number_format((int) $payment->gross_amount, 2, '.', '');

        $hasil = $payments->handleNotification([
            'order_id'           => $payment->midtrans_order_id,
            'status_code'        => $kode,
            'gross_amount'       => $total,
            'transaction_status' => $status,
            'fraud_status'       => 'accept',
            'payment_type'       => $payment->payment_type,
            'transaction_id'     => (string) ($payment->midtrans_transaction_id ?? ''),
            'signature_key'      => hash('sha512', $payment->midtrans_order_id . $kode . $total . $kunci),
        ], '127.0.0.1');

        $this->info("Hasil: {$hasil['status']} (HTTP {$hasil['http']}) - {$hasil['message']}");

        return $hasil['http'] < 400 ? self::SUCCESS : self::FAILURE;
    }
}
