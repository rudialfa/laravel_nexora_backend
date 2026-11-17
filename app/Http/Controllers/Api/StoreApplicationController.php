<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplication\SubmitStoreApplicationRequest;
use App\Http\Resources\StoreApplicationResource;
use App\Services\StoreApplicationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class StoreApplicationController extends Controller
{
    public function __construct(private readonly StoreApplicationService $applications) {}

    /** GET /api/v1/store-applications : riwayat pengajuan milik pengguna ini. */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            StoreApplicationResource::collection($this->applications->mine($request->user()))
        );
    }

    /** POST /api/v1/store-applications (multipart) */
    public function store(SubmitStoreApplicationRequest $request): JsonResponse
    {
        $application = $this->applications->submit(
            $request->user(),
            Arr::except($request->validated(), ['id_photo', 'selfie']),
            $request->file('id_photo'),
            $request->file('selfie'),
            $request
        );

        return ApiResponse::success(
            new StoreApplicationResource($application),
            'Pengajuan toko berhasil dikirim dan menunggu pemeriksaan.',
            201
        );
    }
}
