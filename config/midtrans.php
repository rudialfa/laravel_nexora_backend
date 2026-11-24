<?php

$produksi = filter_var(env('MIDTRANS_IS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN);

return [
    'server_key'    => env('MIDTRANS_SERVER_KEY'),
    'client_key'    => env('MIDTRANS_CLIENT_KEY'),
    'is_production' => $produksi,
    'base_url'      => $produksi ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com',
    'timeout'       => 15,

    // Bank virtual account yang ditawarkan.
    'banks' => ['bca', 'bni', 'bri', 'permata'],

    // QRIS: penyedia QR dan batas waktu bayarnya (menit). Tidak melebihi batas bayar checkout.
    'qris_acquirer'       => 'gopay',
    'qris_expiry_minutes' => 60,

    // Maksimal percobaan pembayaran per checkout.
    'max_attempts' => 5,
];
