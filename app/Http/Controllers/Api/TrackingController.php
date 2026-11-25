<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\OrderService;
use App\Services\ShipmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Resi dan riwayat pelacakan untuk pembeli dan seller. Pesanan pihak lain dijawab 404. */
class TrackingController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ShipmentService $shipments,
    ) {}

    /** GET /api/v1/orders/{order}/tracking */
    public function buyer(Request $request, string $order): JsonResponse
    {
        $pesanan = $this->orders->findForBuyer($request->user(), $order);

        return ApiResponse::success($this->shipments->tracking($pesanan, 'buyer'));
    }

    /** GET /api/v1/seller/orders/{order}/tracking */
    public function seller(Request $request, string $order): JsonResponse
    {
        $toko    = Store::query()->where('owner_id', $request->user()->id)->firstOrFail();
        $pesanan = $this->orders->findForStore($toko, $order);

        return ApiResponse::success($this->shipments->tracking($pesanan, 'seller'));
    }
}
