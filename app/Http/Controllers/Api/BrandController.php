<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ListBrandsRequest;
use App\Http\Resources\PublicBrandResource;
use App\Services\BrandService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class BrandController extends Controller
{
    public function __construct(private readonly BrandService $brands) {}

    /** GET /api/v1/brands */
    public function index(ListBrandsRequest $request): JsonResponse
    {
        $halaman = $this->brands->paginate(
            $request->validated('q'),
            null,
            (int) ($request->validated('per_page') ?? 20),
            true
        );

        return ApiResponse::success(
            PublicBrandResource::collection($halaman->getCollection()),
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
}
