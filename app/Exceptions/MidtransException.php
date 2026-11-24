<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Kegagalan memanggil Midtrans, dengan pesan aman untuk ditampilkan ke pengguna. */
class MidtransException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 503,
        public readonly string $kode = 'unavailable',
    ) {
        parent::__construct($message);
    }

    public function render($request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors'  => ['payment' => [$this->getMessage()]],
        ], $this->httpStatus);
    }
}
