<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Address\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Models\User;
use App\Services\AddressService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addresses) {}

    /** GET /api/v1/addresses : alamat utama paling atas, lalu yang terbaru. */
    public function index(Request $request): JsonResponse
    {
        $daftar = $request->user()->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(AddressResource::collection($daftar));
    }

    /** POST /api/v1/addresses */
    public function store(AddressRequest $request): JsonResponse
    {
        try {
            $alamat = $this->addresses->create($request->user(), $request->validated());
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422, ['address' => [$e->getMessage()]]);
        }

        return ApiResponse::success(new AddressResource($alamat), 'Alamat berhasil ditambahkan.', 201);
    }

    /** GET /api/v1/addresses/{address} */
    public function show(Request $request, string $address): JsonResponse
    {
        return ApiResponse::success(new AddressResource($this->temukan($request->user(), $address)));
    }

    /** PATCH /api/v1/addresses/{address} */
    public function update(AddressRequest $request, string $address): JsonResponse
    {
        $alamat = $this->addresses->update(
            $request->user(),
            $this->temukan($request->user(), $address),
            $request->validated()
        );

        return ApiResponse::success(new AddressResource($alamat), 'Alamat berhasil diperbarui.');
    }

    /** DELETE /api/v1/addresses/{address} */
    public function destroy(Request $request, string $address): JsonResponse
    {
        $this->addresses->delete($request->user(), $this->temukan($request->user(), $address));

        return ApiResponse::success(null, 'Alamat berhasil dihapus.');
    }

    /** POST /api/v1/addresses/{address}/default */
    public function makeDefault(Request $request, string $address): JsonResponse
    {
        $alamat = $this->addresses->setDefault($request->user(), $this->temukan($request->user(), $address));

        return ApiResponse::success(new AddressResource($alamat), 'Alamat utama berhasil diubah.');
    }

    /**
     * Mencari alamat HANYA di antara alamat milik pengguna ini. Alamat milik orang lain
     * (atau yang tidak ada) sama-sama dijawab 404, sehingga keberadaannya tidak bisa dicek.
     */
    private function temukan(User $user, string $uuid): Address
    {
        return $user->addresses()->where('uuid', $uuid)->firstOrFail();
    }
}
