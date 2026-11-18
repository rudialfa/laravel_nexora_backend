<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CatalogImageRequest;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Http\Resources\AdminCategoryResource;
use App\Services\CategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories) {}

    /** GET /api/v1/admin/categories */
    public function index(): JsonResponse
    {
        return ApiResponse::success(AdminCategoryResource::collection($this->categories->adminTree()));
    }

    /** POST /api/v1/admin/categories */
    public function store(CategoryRequest $request): JsonResponse
    {
        $kategori = $this->categories->create($request->user(), $request->validated(), $request);

        return ApiResponse::success(new AdminCategoryResource($kategori), 'Kategori berhasil dibuat.', 201);
    }

    /** PATCH /api/v1/admin/categories/{category} */
    public function update(CategoryRequest $request, string $category): JsonResponse
    {
        $kategori = $this->categories->update(
            $request->user(),
            $this->categories->findByUuid($category),
            $request->validated(),
            $request
        );

        return ApiResponse::success(new AdminCategoryResource($kategori), 'Kategori berhasil diperbarui.');
    }

    /** DELETE /api/v1/admin/categories/{category} */
    public function destroy(Request $request, string $category): JsonResponse
    {
        $this->categories->delete($request->user(), $this->categories->findByUuid($category), $request);

        return ApiResponse::success(null, 'Kategori berhasil dihapus.');
    }

    /** POST /api/v1/admin/categories/{category}/images/{type} (multipart, field "image"; type: icon atau image) */
    public function uploadImage(CatalogImageRequest $request, string $category, string $type): JsonResponse
    {
        $kategori = $this->categories->setImage(
            $request->user(),
            $this->categories->findByUuid($category),
            $type,
            $request->file('image'),
            $request
        );

        return ApiResponse::success(new AdminCategoryResource($kategori), 'Gambar kategori berhasil diperbarui.');
    }

    /** DELETE /api/v1/admin/categories/{category}/images/{type} */
    public function removeImage(Request $request, string $category, string $type): JsonResponse
    {
        $kategori = $this->categories->removeImage(
            $request->user(),
            $this->categories->findByUuid($category),
            $type,
            $request
        );

        return ApiResponse::success(new AdminCategoryResource($kategori), 'Gambar kategori dihapus.');
    }
}
