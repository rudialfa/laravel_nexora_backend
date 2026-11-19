<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBannerResource;
use App\Http\Resources\PublicCategoryResource;
use App\Http\Resources\PublicProductListResource;
use App\Services\ProductCatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    /** GET /api/v1/home */
    public function index(ProductCatalogService $catalog): JsonResponse
    {
        $beranda = $catalog->home();

        return ApiResponse::success([
            'banners'      => PublicBannerResource::collection($beranda['banners']),
            'categories'   => PublicCategoryResource::collection($beranda['categories']),
            'featured'     => PublicProductListResource::collection($beranda['featured']),
            'new_arrivals' => PublicProductListResource::collection($beranda['new_arrivals']),
            'best_sellers' => PublicProductListResource::collection($beranda['best_sellers']),
        ]);
    }
}
