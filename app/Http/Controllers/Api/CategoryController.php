<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicCategoryResource;
use App\Services\CategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories) {}

    /** GET /api/v1/categories */
    public function index(): JsonResponse
    {
        return ApiResponse::success(PublicCategoryResource::collection($this->categories->publicTree()));
    }

    /** GET /api/v1/categories/{slug} */
    public function show(string $slug): JsonResponse
    {
        $kategori = $this->categories->findPublic($slug);

        if ($kategori === null) {
            abort(404);
        }

        return ApiResponse::success(new PublicCategoryResource($kategori));
    }
}
