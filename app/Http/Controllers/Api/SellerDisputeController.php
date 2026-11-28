<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\ListDisputesRequest;
use App\Http\Requests\Dispute\RespondDisputeRequest;
use App\Models\Store;
use App\Services\DisputeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Komplain atas pesanan toko seller yang login. Milik toko lain dijawab 404. */
class SellerDisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    public function index(ListDisputesRequest $request): JsonResponse
    {
        $hasil = $this->disputes->listForStore($this->toko($request), $request->validated());

        return response()->json(['success' => true, 'message' => 'Daftar komplain berhasil diambil.', 'data' => $hasil['items'], 'meta' => $hasil['meta']]);
    }

    public function show(Request $request, string $dispute): JsonResponse
    {
        return ApiResponse::success($this->disputes->present($this->disputes->findForStore($this->toko($request), $dispute), 'seller'));
    }

    public function respond(RespondDisputeRequest $request, string $dispute): JsonResponse
    {
        $foto = $request->file('photos');

        $d = $this->disputes->respond($this->toko($request), $dispute, $request->user(), $request->validated(), is_array($foto) ? $foto : []);

        return ApiResponse::success($this->disputes->present($d, 'seller'), 'Tanggapan terkirim.');
    }

    private function toko(Request $request): Store
    {
        return Store::query()->where('owner_id', $request->user()->id)->firstOrFail();
    }
}
