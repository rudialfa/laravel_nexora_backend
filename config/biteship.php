<?php

return [
    'key'      => env('BITESHIP_API_KEY'),
    'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com'),
    'timeout'  => 10,

    // Cadangan jika toko belum memilih kurir. Kode diambil dari dokumentasi Biteship.
    'default_couriers' => array_filter(explode(',', env('BITESHIP_COURIERS', 'jne,sicepat,anteraja'))),
];
