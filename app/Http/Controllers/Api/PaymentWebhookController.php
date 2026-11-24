<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** POST /api/v1/webhooks/midtrans: tanpa login, keamanannya dari signature. */
    public function midtrans(Request $request): JsonResponse
    {
        $hasil = $this->payments->handleNotification($request->all(), $request->ip());

        return response()->json([
            'success' => $hasil['http'] < 400,
            'message' => $hasil['message'],
        ], $hasil['http']);
    }
}
