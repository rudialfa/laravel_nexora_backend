<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplication\ListStoreApplicationsRequest;
use App\Http\Requests\StoreApplication\RejectStoreApplicationRequest;
use App\Http\Resources\AdminStoreApplicationResource;
use App\Services\StoreApplicationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class StoreApplicationController extends Controller
{
    public function __construct(private readonly StoreApplicationService $applications) {}

    /** GET /api/v1/admin/store-applications */
    public function index(ListStoreApplicationsRequest $request): JsonResponse
    {
        $halaman = $this->applications->paginate(
            $request->validated('status'),
            (int) ($request->validated('per_page') ?? 15)
        );

        return ApiResponse::success(
            AdminStoreApplicationResource::collection($halaman->getCollection()),
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

    /** GET /api/v1/admin/store-applications/{application} : detail beserta NIK (tercatat di audit log). */
    public function show(Request $request, string $application): JsonResponse
    {
        $pengajuan = $this->applications->findByUuid($application);
        $nik       = $this->applications->revealIdNumber($request->user(), $pengajuan, $request);

        return ApiResponse::success(
            (new AdminStoreApplicationResource($pengajuan))->resolve($request) + $nik
        );
    }

    /** GET /api/v1/admin/store-applications/{application}/photos/{type} (type: id atau selfie) */
    public function photo(Request $request, string $application, string $type): Response
    {
        $pengajuan = $this->applications->findByUuid($application);
        $path      = $this->applications->photoPath($request->user(), $pengajuan, $type, $request);

        if ($path === null) {
            abort(404);
        }

        return Storage::disk('local')->response($path, null, [
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /api/v1/admin/store-applications/{application}/approve */
    public function approve(Request $request, string $application): JsonResponse
    {
        $hasil = $this->applications->approve(
            $request->user(),
            $this->applications->findByUuid($application),
            $request
        );

        return ApiResponse::success(new AdminStoreApplicationResource($hasil), 'Pengajuan toko disetujui.');
    }

    /** POST /api/v1/admin/store-applications/{application}/reject */
    public function reject(RejectStoreApplicationRequest $request, string $application): JsonResponse
    {
        $hasil = $this->applications->reject(
            $request->user(),
            $this->applications->findByUuid($application),
            $request->validated('reason'),
            $request
        );

        return ApiResponse::success(new AdminStoreApplicationResource($hasil), 'Pengajuan toko ditolak.');
    }
}
