<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\PayCheckoutRequest;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** POST /api/v1/checkout/{group}/pay */
    public function pay(PayCheckoutRequest $request, string $group): JsonResponse
    {
        $hasil = $this->payments->pay($request->user(), $group, $request->validated());
        $data  = $this->payments->present($hasil['payment']);

        return $hasil['created']
            ? ApiResponse::success($data, 'Pembayaran berhasil dibuat.', 201)
            : ApiResponse::success($data, 'Pembayaran yang masih berlaku dikembalikan.');
    }

    /** GET /api/v1/checkout/{group}/payment */
    public function show(Request $request, string $group): JsonResponse
    {
        return ApiResponse::success($this->payments->present($this->payments->latest($request->user(), $group)));
    }
}
