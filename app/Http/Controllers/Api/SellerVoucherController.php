<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voucher\ListVouchersRequest;
use App\Http\Requests\Voucher\StoreVoucherRequest;
use App\Http\Requests\Voucher\UpdateVoucherRequest;
use App\Models\Store;
use App\Services\VoucherService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Voucher milik toko seller yang login. Voucher toko lain dan voucher platform dijawab 404. */
class SellerVoucherController extends Controller
{
    public function __construct(private readonly VoucherService $vouchers) {}

    public function index(ListVouchersRequest $request): JsonResponse
    {
        $hasil = $this->vouchers->paginate($this->toko($request)->id, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Daftar voucher berhasil diambil.',
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
        ]);
    }

    public function show(Request $request, string $voucher): JsonResponse
    {
        return ApiResponse::success($this->vouchers->present($this->vouchers->find($this->toko($request)->id, $voucher)));
    }

    public function store(StoreVoucherRequest $request): JsonResponse
    {
        $baru = $this->vouchers->create($request->validated(), $this->toko($request), $request->user());

        return ApiResponse::success($this->vouchers->present($baru), 'Voucher berhasil dibuat.', 201);
    }

    public function update(UpdateVoucherRequest $request, string $voucher): JsonResponse
    {
        $ubah = $this->vouchers->update($this->vouchers->find($this->toko($request)->id, $voucher), $request->validated());

        return ApiResponse::success($this->vouchers->present($ubah), 'Voucher diperbarui.');
    }

    public function destroy(Request $request, string $voucher): JsonResponse
    {
        $this->vouchers->delete($this->vouchers->find($this->toko($request)->id, $voucher));

        return ApiResponse::success([], 'Voucher dihapus.');
    }

    private function toko(Request $request): Store
    {
        return Store::query()->where('owner_id', $request->user()->id)->firstOrFail();
    }
}
