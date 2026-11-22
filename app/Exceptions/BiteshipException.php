<?php

namespace App\Exceptions;

use RuntimeException;

/** Kegagalan memanggil Biteship, dengan pesan aman untuk ditampilkan ke pengguna. */
class BiteshipException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 503,
        public readonly string $kode = 'unavailable',
    ) {
        parent::__construct($message);
    }
}
