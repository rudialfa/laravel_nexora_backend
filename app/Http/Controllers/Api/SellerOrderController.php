<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\ListOrdersRequest;
use App\Http\Requests\Order\ShipOrderRequest;
use App\Models\Store;
use App\Services\OrderService;
use App\Services\ShipmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pesanan toko seller yang login. Pesanan toko lain dan pesanan yang belum dibayar dijawab 404. */
class SellerOrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ShipmentService $shipments,
    ) {}

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

    /** POST /api/v1/seller/orders/{order}/ship: meminta kurir menjemput paket. */
    public function ship(ShipOrderRequest $request, string $order): JsonResponse
    {
        $hasil = $this->shipments->ship($this->toko($request), $order, $request->user(), $request->validated());

        return ApiResponse::success(
            $this->orders->present($hasil, 'seller'),
            'Pengiriman dibuat. Kurir akan menjemput sesuai jadwal.',
            201
        );
    }

    public function cancel(CancelOrderRequest $request, string $order): JsonResponse
    {
        $hasil = $this->orders->sellerCancel($this->toko($request), $order, $request->user(), $request->validated('reason'));

        // Setelah pesanan benar-benar batal: order Biteship ikut dibatalkan (jika belum dijemput).
        $this->shipments->cancelBiteship($hasil, $request->validated('reason'));

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
