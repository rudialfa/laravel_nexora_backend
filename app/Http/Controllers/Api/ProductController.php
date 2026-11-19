<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProductResource;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /** GET /api/v1/products/{slug} */
    public function show(string $slug): JsonResponse
    {
        $produk = $this->products->findPublic($slug);

        if ($produk === null) {
            abort(404);
        }

        return ApiResponse::success(new PublicProductResource($produk));
    }
}
