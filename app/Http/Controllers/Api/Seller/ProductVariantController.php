<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StockAdjustRequest;
use App\Http\Requests\Seller\VariantRequest;
use App\Http\Resources\SellerProductResource;
use App\Models\Product;
use App\Models\Store;
use App\Services\ProductService;
use App\Services\VariantService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly VariantService $variants
    ) {}

    /** POST /api/v1/seller/products/{product}/variants */
    public function store(VariantRequest $request, string $product): JsonResponse
    {
        $hasil = $this->variants->add($this->produkSaya($request, $product), $request->user(), $request->validated(), $request);

        return ApiResponse::success(new SellerProductResource($hasil), 'Varian berhasil ditambahkan.', 201);
    }

    /** PATCH /api/v1/seller/products/{product}/variants/{variant} */
    public function update(VariantRequest $request, string $product, string $variant): JsonResponse
    {
        $hasil = $this->variants->update(
            $this->produkSaya($request, $product),
            $variant,
            $request->user(),
            $request->validated(),
            $request
        );

        return ApiResponse::success(new SellerProductResource($hasil), 'Varian berhasil diperbarui.');
    }

    /** DELETE /api/v1/seller/products/{product}/variants/{variant} */
    public function destroy(Request $request, string $product, string $variant): JsonResponse
    {
        $hasil = $this->variants->delete($this->produkSaya($request, $product), $variant, $request->user(), $request);

        return ApiResponse::success(new SellerProductResource($hasil), 'Varian berhasil dihapus.');
    }

    /** POST /api/v1/seller/products/{product}/variants/{variant}/stock */
    public function adjustStock(StockAdjustRequest $request, string $product, string $variant): JsonResponse
    {
        $hasil = $this->variants->adjustStock(
            $this->produkSaya($request, $product),
            $variant,
            $request->user(),
            (int) $request->validated('delta'),
            $request->validated('note'),
            $request
        );

        return ApiResponse::success(new SellerProductResource($hasil), 'Stok berhasil diperbarui.');
    }

    /** Produk milik toko pengguna yang login; milik toko lain atau yang tidak ada: 404. */
    private function produkSaya(Request $request, string $uuid): Product
    {
        /** @var Store $toko */
        $toko = $request->attributes->get('store');

        return $this->products->findOwned($toko, $uuid);
    }
}
