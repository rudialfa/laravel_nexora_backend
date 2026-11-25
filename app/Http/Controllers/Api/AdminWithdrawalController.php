<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListWithdrawalsRequest;
use App\Http\Requests\Finance\PayWithdrawalRequest;
use App\Http\Requests\Finance\RejectWithdrawalRequest;
use App\Services\WithdrawalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function index(ListWithdrawalsRequest $request): JsonResponse
    {
        $hasil = $this->withdrawals->listAll($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Daftar penarikan berhasil diambil.',
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
        ]);
    }

    public function show(string $withdrawal): JsonResponse
    {
        return ApiResponse::success($this->withdrawals->present($this->withdrawals->find($withdrawal), true, true));
    }

    public function approve(Request $request, string $withdrawal): JsonResponse
    {
        $w = $this->withdrawals->approve($withdrawal, $request->user());

        return ApiResponse::success($this->withdrawals->present($w, true, true), 'Penarikan disetujui.');
    }

    public function pay(PayWithdrawalRequest $request, string $withdrawal): JsonResponse
    {
        $w = $this->withdrawals->markPaid($withdrawal, $request->user(), $request->validated('transfer_reference'));

        return ApiResponse::success($this->withdrawals->present($w, true, true), 'Penarikan ditandai sudah dibayar.');
    }

    public function reject(RejectWithdrawalRequest $request, string $withdrawal): JsonResponse
    {
        $w = $this->withdrawals->reject($withdrawal, $request->user(), $request->validated('reason'));

        return ApiResponse::success($this->withdrawals->present($w, true, true), 'Penarikan ditolak dan dana dikembalikan ke saldo toko.');
    }
}
