<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\BrandRequest;
use App\Http\Requests\Catalog\CatalogImageRequest;
use App\Http\Requests\Catalog\ListBrandsRequest;
use App\Http\Resources\AdminBrandResource;
use App\Services\BrandService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function __construct(private readonly BrandService $brands) {}

    /** GET /api/v1/admin/brands */
    public function index(ListBrandsRequest $request): JsonResponse
    {
        $halaman = $this->brands->paginate(
            $request->validated('q'),
            $request->filled('is_active') ? $request->boolean('is_active') : null,
            (int) ($request->validated('per_page') ?? 20),
            false
        );

        return ApiResponse::success(
            AdminBrandResource::collection($halaman->getCollection()),
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

    /** POST /api/v1/admin/brands */
    public function store(BrandRequest $request): JsonResponse
    {
        $brand = $this->brands->create($request->user(), $request->validated(), $request);

        return ApiResponse::success(new AdminBrandResource($brand), 'Brand berhasil dibuat.', 201);
    }

    /** PATCH /api/v1/admin/brands/{brand} */
    public function update(BrandRequest $request, string $brand): JsonResponse
    {
        $hasil = $this->brands->update(
            $request->user(),
            $this->brands->findByUuid($brand),
            $request->validated(),
            $request
        );

        return ApiResponse::success(new AdminBrandResource($hasil), 'Brand berhasil diperbarui.');
    }

    /** DELETE /api/v1/admin/brands/{brand} */
    public function destroy(Request $request, string $brand): JsonResponse
    {
        $this->brands->delete($request->user(), $this->brands->findByUuid($brand), $request);

        return ApiResponse::success(null, 'Brand berhasil dihapus.');
    }

    /** POST /api/v1/admin/brands/{brand}/logo (multipart, field "image") */
    public function uploadLogo(CatalogImageRequest $request, string $brand): JsonResponse
    {
        $hasil = $this->brands->setLogo(
            $request->user(),
            $this->brands->findByUuid($brand),
            $request->file('image'),
            $request
        );

        return ApiResponse::success(new AdminBrandResource($hasil), 'Logo brand berhasil diperbarui.');
    }

    /** DELETE /api/v1/admin/brands/{brand}/logo */
    public function removeLogo(Request $request, string $brand): JsonResponse
    {
        $hasil = $this->brands->removeLogo($request->user(), $this->brands->findByUuid($brand), $request);

        return ApiResponse::success(new AdminBrandResource($hasil), 'Logo brand dihapus.');
    }
}
