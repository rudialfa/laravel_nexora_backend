<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\StoreImageRequest;
use App\Http\Requests\Seller\UpdateCouriersRequest;
use App\Http\Requests\Seller\UpdateOperatingHoursRequest;
use App\Http\Requests\Seller\UpdateStoreRequest;
use App\Http\Resources\SellerStoreResource;
use App\Models\Store;
use App\Services\StoreManagementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function __construct(private readonly StoreManagementService $stores) {}

    /** GET /api/v1/seller/store */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            new SellerStoreResource($this->store($request)->loadMissing(['operatingHours', 'courierSettings']))
        );
    }

    /** PATCH /api/v1/seller/store */
    public function update(UpdateStoreRequest $request): JsonResponse
    {
        $toko = $this->stores->update($this->store($request), $request->user(), $request->validated(), $request);

        return ApiResponse::success(new SellerStoreResource($toko), 'Profil toko berhasil diperbarui.');
    }

    /** POST /api/v1/seller/store/images/{type} (multipart, field "image"; type: logo atau banner) */
    public function uploadImage(StoreImageRequest $request, string $type): JsonResponse
    {
        $toko = $this->stores->setImage($this->store($request), $request->user(), $type, $request->file('image'), $request);

        return ApiResponse::success(new SellerStoreResource($toko), 'Gambar toko berhasil diperbarui.');
    }

    /** DELETE /api/v1/seller/store/images/{type} */
    public function removeImage(Request $request, string $type): JsonResponse
    {
        $toko = $this->stores->removeImage($this->store($request), $request->user(), $type, $request);

        return ApiResponse::success(new SellerStoreResource($toko), 'Gambar toko dihapus.');
    }

    /** PUT /api/v1/seller/store/operating-hours */
    public function updateHours(UpdateOperatingHoursRequest $request): JsonResponse
    {
        $toko = $this->stores->replaceHours($this->store($request), $request->user(), $request->validated('hours'), $request);

        return ApiResponse::success(new SellerStoreResource($toko), 'Jadwal buka berhasil diperbarui.');
    }

    /** PUT /api/v1/seller/store/couriers */
    public function updateCouriers(UpdateCouriersRequest $request): JsonResponse
    {
        $toko = $this->stores->replaceCouriers($this->store($request), $request->user(), $request->validated('couriers'), $request);

        return ApiResponse::success(new SellerStoreResource($toko), 'Pengaturan kurir berhasil diperbarui.');
    }

    /** Toko milik pengguna yang login, disiapkan oleh middleware ResolveSellerStore. */
    private function store(Request $request): Store
    {
        return $request->attributes->get('store');
    }
}
