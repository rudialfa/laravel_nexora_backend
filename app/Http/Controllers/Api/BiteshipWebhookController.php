<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BiteshipWebhookController extends Controller
{
    public function __construct(private readonly ShipmentService $shipments) {}

    /** POST /api/v1/webhooks/biteship: tanpa login, keamanannya dari token rahasia. */
    public function handle(Request $request): JsonResponse
    {
        $hasil = $this->shipments->handleNotification($request->all(), $request->ip(), $this->tokenSah($request));

        return response()->json([
            'success' => $hasil['http'] < 400,
            'message' => $hasil['message'],
        ], $hasil['http']);
    }

    /** Token boleh lewat header X-Webhook-Token, header Authorization (dengan atau tanpa Bearer), atau ?token=. */
    private function tokenSah(Request $request): bool
    {
        $kunci = (string) config('biteship.webhook_token');

        if ($kunci === '') {
            Log::warning('Webhook Biteship ditolak: BITESHIP_WEBHOOK_TOKEN kosong.');

            return false;
        }

        foreach ([$request->header('X-Webhook-Token'), $request->header('Authorization'), $request->query('token')] as $calon) {
            if (! is_string($calon) || $calon === '') {
                continue;
            }

            if (hash_equals($kunci, trim((string) preg_replace('/^Bearer\s+/i', '', $calon)))) {
                return true;
    }
        }

        return false;
    }
}
