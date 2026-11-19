<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ListProductsRequest;
use App\Http\Resources\PublicProductListResource;
use App\Http\Resources\PublicProductResource;
use App\Services\ProductCatalogService;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly ProductCatalogService $catalog
    ) {}

    /** GET /api/v1/products */
    public function index(ListProductsRequest $request): JsonResponse
    {
        $halaman = $this->catalog->search(
            $request->validated(),
            (int) ($request->validated('per_page') ?? 20)
        );

        return ApiResponse::success(
            PublicProductListResource::collection($halaman->getCollection()),
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

    /** GET /api/v1/products/{slug} */
    public function show(string $slug): JsonResponse
    {
        $produk = $this->products->findPublic($slug);

        if ($produk === null) {
            abort(404);
        }

        return ApiResponse::success(new PublicProductResource($produk));
    }

    /** GET /api/v1/products/{slug}/related */
    public function related(string $slug): JsonResponse
    {
        $terkait = $this->catalog->related($slug);

        if ($terkait === null) {
            abort(404);
        }

        return ApiResponse::success(PublicProductListResource::collection($terkait));
    }
}
