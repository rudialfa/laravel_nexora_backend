<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\ApproveDisputeRequest;
use App\Http\Requests\Dispute\CompleteRefundRequest;
use App\Http\Requests\Dispute\FailRefundRequest;
use App\Http\Requests\Dispute\ListDisputesRequest;
use App\Http\Requests\Dispute\ListRefundsRequest;
use App\Http\Requests\Dispute\RejectDisputeRequest;
use App\Services\DisputeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    public function index(ListDisputesRequest $request): JsonResponse
    {
        $hasil = $this->disputes->listAll($request->validated());

        return response()->json(['success' => true, 'message' => 'Daftar komplain berhasil diambil.', 'data' => $hasil['items'], 'meta' => $hasil['meta']]);
    }

    public function show(string $dispute): JsonResponse
    {
        return ApiResponse::success($this->disputes->present($this->disputes->find($dispute), 'admin'));
    }

    public function review(Request $request, string $dispute): JsonResponse
    {
        return ApiResponse::success($this->disputes->present($this->disputes->review($dispute, $request->user()), 'admin'), 'Komplain mulai ditinjau.');
    }

    public function approve(ApproveDisputeRequest $request, string $dispute): JsonResponse
    {
        $d = $this->disputes->approve($dispute, $request->user(), $request->validated());

        return ApiResponse::success($this->disputes->present($d, 'admin'), 'Komplain disetujui.');
    }

    public function reject(RejectDisputeRequest $request, string $dispute): JsonResponse
    {
        $d = $this->disputes->reject($dispute, $request->user(), $request->validated('reason'));

        return ApiResponse::success($this->disputes->present($d, 'admin'), 'Komplain ditolak.');
    }

    public function retryRefund(string $dispute): JsonResponse
    {
        return ApiResponse::success($this->disputes->present($this->disputes->retryRefund($dispute), 'admin'), 'Pengembalian dana baru dibuat.', 201);
    }

    public function refunds(ListRefundsRequest $request): JsonResponse
    {
        $hasil = $this->disputes->listRefunds($request->validated());

        return response()->json(['success' => true, 'message' => 'Daftar pengembalian dana berhasil diambil.', 'data' => $hasil['items'], 'meta' => $hasil['meta']]);
    }

    public function completeRefund(CompleteRefundRequest $request, string $refund): JsonResponse
    {
        $r = $this->disputes->completeRefund($refund, $request->user(), $request->validated('reference'));

        return ApiResponse::success($this->disputes->presentRefund($r, true), 'Pengembalian dana ditandai selesai.');
    }

    public function failRefund(FailRefundRequest $request, string $refund): JsonResponse
    {
        $r = $this->disputes->failRefund($refund, $request->user(), $request->validated('reason'));

        return ApiResponse::success($this->disputes->presentRefund($r, true), 'Pengembalian dana ditandai gagal.');
    }
}
