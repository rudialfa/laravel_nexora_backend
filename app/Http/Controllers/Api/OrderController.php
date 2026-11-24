<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ListOrdersRequest;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pesanan milik pembeli yang login. */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** GET /api/v1/orders */
    public function index(ListOrdersRequest $request): JsonResponse
    {
        $hasil = $this->orders->listForBuyer($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Daftar pesanan berhasil diambil.',
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
        ]);
    }

    /** GET /api/v1/orders/{order} */
    public function show(Request $request, string $order): JsonResponse
    {
        return ApiResponse::success($this->orders->present($this->orders->findForBuyer($request->user(), $order), 'buyer'));
    }

    /** GET /api/v1/orders/{order}/invoice */
    public function invoice(Request $request, string $order): JsonResponse
    {
        return ApiResponse::success($this->orders->invoice($request->user(), $order));
    }

    /** POST /api/v1/orders/{order}/cancel */
    public function cancel(Request $request, string $order): JsonResponse
    {
        $hasil = $this->orders->buyerCancel($request->user(), $order);

        return ApiResponse::success(
            $this->orders->present($hasil, 'buyer'),
            'Checkout dibatalkan. Seluruh pesanan dalam checkout ini ikut dibatalkan.'
        );
    }

    /** POST /api/v1/orders/{order}/complete */
    public function complete(Request $request, string $order): JsonResponse
    {
        return ApiResponse::success(
            $this->orders->present($this->orders->buyerComplete($request->user(), $order), 'buyer'),
            'Pesanan dikonfirmasi diterima.'
        );
    }
}
