<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voucher\CheckVoucherRequest;
use App\Services\VoucherService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class VoucherController extends Controller
{
    public function __construct(private readonly VoucherService $vouchers) {}

    /** POST /api/v1/vouchers/validate */
    public function check(CheckVoucherRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->vouchers->check($request->user(), $request->validated('code')),
            'Voucher dapat digunakan.'
        );
    }
}
