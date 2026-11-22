<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voucher\ListVouchersRequest;
use App\Http\Requests\Voucher\StoreVoucherRequest;
use App\Http\Requests\Voucher\UpdateVoucherRequest;
use App\Services\VoucherService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Voucher platform (berlaku untuk seluruh toko). Voucher toko bukan urusan controller ini. */
class AdminVoucherController extends Controller
{
    public function __construct(private readonly VoucherService $vouchers) {}

    public function index(ListVouchersRequest $request): JsonResponse
    {
        $hasil = $this->vouchers->paginate(null, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Daftar voucher berhasil diambil.',
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
        ]);
    }

    public function show(string $voucher): JsonResponse
    {
        return ApiResponse::success($this->vouchers->present($this->vouchers->find(null, $voucher)));
    }

    public function store(StoreVoucherRequest $request): JsonResponse
    {
        $baru = $this->vouchers->create($request->validated(), null, $request->user());

        return ApiResponse::success($this->vouchers->present($baru), 'Voucher berhasil dibuat.', 201);
    }

    public function update(UpdateVoucherRequest $request, string $voucher): JsonResponse
    {
        $ubah = $this->vouchers->update($this->vouchers->find(null, $voucher), $request->validated());

        return ApiResponse::success($this->vouchers->present($ubah), 'Voucher diperbarui.');
    }

    public function destroy(string $voucher): JsonResponse
    {
        $this->vouchers->delete($this->vouchers->find(null, $voucher));

        return ApiResponse::success([], 'Voucher dihapus.');
    }
}
