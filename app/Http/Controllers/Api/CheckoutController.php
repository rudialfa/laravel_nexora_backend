<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CreateCheckoutRequest;
use App\Http\Requests\Checkout\PreviewCheckoutRequest;
use App\Services\CheckoutService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    /** POST /api/v1/checkout/preview */
    public function preview(PreviewCheckoutRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->checkout->preview($request->user(), $request->validated()),
            'Rincian checkout berhasil dihitung.'
        );
    }

    /** POST /api/v1/checkout */
    public function store(CreateCheckoutRequest $request): JsonResponse
    {
        $hasil = $this->checkout->create($request->user(), $request->validated());
        $data  = $this->checkout->present($hasil['group']);

        return $hasil['created']
            ? ApiResponse::success($data, 'Checkout berhasil dibuat.', 201)
            : ApiResponse::success($data, 'Checkout sudah dibuat sebelumnya.');
    }

    /** GET /api/v1/checkout/{group} */
    public function show(Request $request, string $group): JsonResponse
    {
        return ApiResponse::success($this->checkout->present($this->checkout->find($request->user(), $group)));
    }
}
