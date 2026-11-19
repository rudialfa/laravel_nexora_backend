<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicStoreResource;
use App\Services\ProductCatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PublicStoreController extends Controller
{
    public function __construct(private readonly ProductCatalogService $catalog) {}

    /** GET /api/v1/stores/{slug} */
    public function show(string $slug): JsonResponse
    {
        $toko = $this->catalog->findStore($slug);

        if ($toko === null) {
            abort(404);
        }

        return ApiResponse::success(new PublicStoreResource($toko));
    }
}
