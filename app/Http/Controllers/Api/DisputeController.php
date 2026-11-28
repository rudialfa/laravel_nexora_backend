<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\CreateDisputeRequest;
use App\Http\Requests\Dispute\ListDisputesRequest;
use App\Services\DisputeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Komplain milik pembeli yang login. */
class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    /** POST /api/v1/orders/{order}/dispute */
    public function store(CreateDisputeRequest $request, string $order): JsonResponse
    {
        $foto = $request->file('photos');

        $d = $this->disputes->create($request->user(), $order, $request->validated(), is_array($foto) ? $foto : []);

        return ApiResponse::success($this->disputes->present($d, 'buyer'), 'Komplain berhasil diajukan.', 201);
    }

    /** GET /api/v1/disputes */
    public function index(ListDisputesRequest $request): JsonResponse
    {
        $hasil = $this->disputes->listForBuyer($request->user(), $request->validated());

        return response()->json(['success' => true, 'message' => 'Daftar komplain berhasil diambil.', 'data' => $hasil['items'], 'meta' => $hasil['meta']]);
    }

    /** GET /api/v1/disputes/{dispute} */
    public function show(Request $request, string $dispute): JsonResponse
    {
        return ApiResponse::success($this->disputes->present($this->disputes->findForBuyer($request->user(), $dispute), 'buyer'));
    }
}
