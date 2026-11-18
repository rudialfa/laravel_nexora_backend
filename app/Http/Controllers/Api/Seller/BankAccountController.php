<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\BankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\Store;
use App\Services\BankAccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function __construct(private readonly BankAccountService $accounts) {}

    /** GET /api/v1/seller/store/bank-accounts */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            BankAccountResource::collection($this->accounts->list($this->tokoSaya($request)))
        );
    }

    /** POST /api/v1/seller/store/bank-accounts */
    public function store(BankAccountRequest $request): JsonResponse
    {
        $rekening = $this->accounts->create($this->tokoSaya($request), $request->user(), $request->validated(), $request);

        return ApiResponse::success(new BankAccountResource($rekening), 'Rekening berhasil ditambahkan.', 201);
    }

    /** DELETE /api/v1/seller/store/bank-accounts/{account} */
    public function destroy(Request $request, string $account): JsonResponse
    {
        $toko = $this->tokoSaya($request);

        $this->accounts->delete($toko, $request->user(), $this->accounts->find($toko, $account), $request);

        return ApiResponse::success(null, 'Rekening berhasil dihapus.');
    }

    /** POST /api/v1/seller/store/bank-accounts/{account}/default */
    public function makeDefault(Request $request, string $account): JsonResponse
    {
        $toko     = $this->tokoSaya($request);
        $rekening = $this->accounts->makeDefault($toko, $request->user(), $this->accounts->find($toko, $account), $request);

        return ApiResponse::success(new BankAccountResource($rekening), 'Rekening utama berhasil diubah.');
    }

    /** Toko milik pengguna yang login, disiapkan oleh middleware ResolveSellerStore. */
    private function tokoSaya(Request $request): Store
    {
        return $request->attributes->get('store');
    }
}
