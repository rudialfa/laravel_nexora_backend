<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\ListOrdersRequest;
use App\Models\Store;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pesanan toko seller yang login. Pesanan toko lain dan pesanan yang belum dibayar dijawab 404. */
class SellerOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $hasil = $this->orders->listForStore($this->toko($request), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Daftar pesanan berhasil diambil.',
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
        ]);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        return ApiResponse::success($this->orders->present($this->orders->findForStore($this->toko($request), $order), 'seller'));
    }

    public function process(Request $request, string $order): JsonResponse
    {
        $hasil = $this->orders->process($this->toko($request), $order, $request->user());

        return ApiResponse::success($this->orders->present($hasil, 'seller'), 'Pesanan diproses.');
    }

    public function pack(Request $request, string $order): JsonResponse
    {
        $hasil = $this->orders->pack($this->toko($request), $order, $request->user());

        return ApiResponse::success($this->orders->present($hasil, 'seller'), 'Pesanan ditandai sudah dikemas.');
    }

    public function cancel(CancelOrderRequest $request, string $order): JsonResponse
    {
        $hasil = $this->orders->sellerCancel($this->toko($request), $order, $request->user(), $request->validated('reason'));

        return ApiResponse::success(
            $this->orders->present($hasil, 'seller'),
            'Pesanan dibatalkan. Pengembalian dana dicatat untuk pembeli.'
        );
    }

    private function toko(Request $request): Store
    {
        return Store::query()->where('owner_id', $request->user()->id)->firstOrFail();
    }
}
