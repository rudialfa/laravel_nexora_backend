<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\ListSellerProductsRequest;
use App\Http\Requests\Seller\ProductImageRequest;
use App\Http\Requests\Seller\ProductRequest;
use App\Http\Resources\SellerProductResource;
use App\Models\Store;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /** GET /api/v1/seller/products */
    public function index(ListSellerProductsRequest $request): JsonResponse
    {
        $halaman = $this->products->paginate(
            $this->tokoSaya($request),
            $request->validated('status'),
            $request->validated('q'),
            (int) ($request->validated('per_page') ?? 15)
        );

        return ApiResponse::success(
            SellerProductResource::collection($halaman->getCollection()),
            'Berhasil.',
            200,
            [
                'current_page' => $halaman->currentPage(),
                'per_page'     => $halaman->perPage(),
                'total'        => $halaman->total(),
                'last_page'    => $halaman->lastPage(),
            ]
        );
    }

    /** POST /api/v1/seller/products */
    public function store(ProductRequest $request): JsonResponse
    {
        $produk = $this->products->create($this->tokoSaya($request), $request->user(), $request->validated(), $request);

        return ApiResponse::success(new SellerProductResource($produk), 'Produk berhasil dibuat sebagai draf.', 201);
    }

    /** GET /api/v1/seller/products/{product} */
    public function show(Request $request, string $product): JsonResponse
    {
        $produk = $this->products->findOwned($this->tokoSaya($request), $product)
            ->load(['category', 'brand', 'images', 'variants']);

        return ApiResponse::success(new SellerProductResource($produk));
    }

    /** PATCH /api/v1/seller/products/{product} */
    public function update(ProductRequest $request, string $product): JsonResponse
    {
        $produk = $this->products->update(
            $request->user(),
            $this->products->findOwned($this->tokoSaya($request), $product),
            $request->validated(),
            $request
        );

        return ApiResponse::success(new SellerProductResource($produk), 'Produk berhasil diperbarui.');
    }

    /** DELETE /api/v1/seller/products/{product} */
    public function destroy(Request $request, string $product): JsonResponse
    {
        $this->products->delete(
            $request->user(),
            $this->products->findOwned($this->tokoSaya($request), $product),
            $request
        );

        return ApiResponse::success(null, 'Produk berhasil dihapus.');
    }

    /** POST /api/v1/seller/products/{product}/publish */
    public function publish(Request $request, string $product): JsonResponse
    {
        $produk = $this->products->publish(
            $request->user(),
            $this->products->findOwned($this->tokoSaya($request), $product),
            $request
        );

        return ApiResponse::success(new SellerProductResource($produk), 'Produk berhasil ditayangkan.');
    }

    /** POST /api/v1/seller/products/{product}/unpublish */
    public function unpublish(Request $request, string $product): JsonResponse
    {
        $produk = $this->products->unpublish(
            $request->user(),
            $this->products->findOwned($this->tokoSaya($request), $product),
            $request
        );

        return ApiResponse::success(new SellerProductResource($produk), 'Penayangan produk dihentikan.');
    }

    /** POST /api/v1/seller/products/{product}/images (multipart, field "image") */
    public function uploadImage(ProductImageRequest $request, string $product): JsonResponse
    {
        $produk = $this->products->addImage(
            $request->user(),
            $this->products->findOwned($this->tokoSaya($request), $product),
            $request->file('image'),
            $request
        );

        return ApiResponse::success(new SellerProductResource($produk), 'Foto produk berhasil ditambahkan.', 201);
    }

    /** DELETE /api/v1/seller/products/{product}/images/{image} */
    public function destroyImage(Request $request, string $product, string $image): JsonResponse
    {
        $produk = $this->products->removeImage(
            $request->user(),
            $this->products->findOwned($this->tokoSaya($request), $product),
            $image,
            $request
        );

        return ApiResponse::success(new SellerProductResource($produk), 'Foto produk dihapus.');
    }

    /** Toko milik pengguna yang login, disiapkan oleh middleware ResolveSellerStore. */
    private function tokoSaya(Request $request): Store
    {
        return $request->attributes->get('store');
    }
}
