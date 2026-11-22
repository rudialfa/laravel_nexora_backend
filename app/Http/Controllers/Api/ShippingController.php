<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\GetShippingRatesRequest;
use App\Http\Requests\Shipping\SearchAreasRequest;
use App\Services\BiteshipService;
use App\Services\ShippingQuoteService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ShippingController extends Controller
{
    public function __construct(
        private readonly BiteshipService $biteship,
        private readonly ShippingQuoteService $quote,
    ) {}

    /** GET /api/v1/shipping/areas?q=... */
    public function areas(SearchAreasRequest $request): JsonResponse
    {
        return ApiResponse::success($this->biteship->searchAreas($request->validated('q')));
    }

    /** POST /api/v1/shipping/rates */
    public function rates(GetShippingRatesRequest $request): JsonResponse
    {
        $hasil = $this->quote->quote(
            $request->user(),
            $request->validated('store_id'),
            $request->validated('address_id')
        );

        return ApiResponse::success(
            $hasil,
            $hasil['options'] === []
                ? 'Tidak ada layanan pengiriman untuk tujuan ini.'
                : 'Pilihan ongkos kirim berhasil diambil.'
        );
    }
}
