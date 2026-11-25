<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListWalletTransactionsRequest;
use App\Http\Requests\Finance\ListWithdrawalsRequest;
use App\Http\Requests\Finance\RequestWithdrawalRequest;
use App\Models\Store;
use App\Services\FinanceService;
use App\Services\OrderService;
use App\Services\WithdrawalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Keuangan toko seller yang login. Data toko lain tidak pernah terlihat. */
class SellerFinanceController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly WithdrawalService $withdrawals,
        private readonly OrderService $orders,
    ) {}

    /** GET /api/v1/seller/wallet */
    public function wallet(Request $request): JsonResponse
    {
        return ApiResponse::success($this->finance->summary($this->toko($request)));
    }

    /** GET /api/v1/seller/wallet/transactions */
    public function transactions(ListWalletTransactionsRequest $request): JsonResponse
    {
        $hasil = $this->finance->ledger($this->toko($request), $request->validated());

        return $this->daftar('Riwayat saldo berhasil diambil.', $hasil);
    }

    /** GET /api/v1/seller/orders/{order}/earnings */
    public function earnings(Request $request, string $order): JsonResponse
    {
        $pesanan = $this->orders->findForStore($this->toko($request), $order);

        return ApiResponse::success($this->finance->orderEarnings($pesanan));
    }

    /** GET /api/v1/seller/withdrawals */
    public function withdrawals(ListWithdrawalsRequest $request): JsonResponse
    {
        return $this->daftar('Daftar penarikan berhasil diambil.', $this->withdrawals->listForStore($this->toko($request), $request->validated()));
    }

    /** GET /api/v1/seller/withdrawals/{withdrawal} */
    public function showWithdrawal(Request $request, string $withdrawal): JsonResponse
    {
        return ApiResponse::success($this->withdrawals->present($this->withdrawals->findForStore($this->toko($request), $withdrawal)));
    }

    /** POST /api/v1/seller/withdrawals */
    public function requestWithdrawal(RequestWithdrawalRequest $request): JsonResponse
    {
        $w = $this->withdrawals->request(
            $this->toko($request),
            $request->user(),
            $request->validated('bank_account_id'),
            (int) $request->validated('amount')
        );

        return ApiResponse::success($this->withdrawals->present($w), 'Pengajuan penarikan dibuat.', 201);
    }

    /** @param  array{items: array<int, array<string, mixed>>, meta: array<string, int>}  $hasil */
    private function daftar(string $pesan, array $hasil): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $pesan, 'data' => $hasil['items'], 'meta' => $hasil['meta']]);
}

    private function toko(Request $request): Store
    {
        return Store::query()->where('owner_id', $request->user()->id)->firstOrFail();
    }
}
