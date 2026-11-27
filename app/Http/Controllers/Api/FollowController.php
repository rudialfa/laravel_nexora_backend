<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\ListFollowsRequest;
use App\Models\Store;
use App\Services\FollowService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    public function __construct(private readonly FollowService $follows) {}

    /** POST /api/v1/stores/{slug}/follow */
    public function follow(Request $request, string $slug): JsonResponse
    {
        $hasil = $this->follows->follow($request->user(), $slug);
        $data  = ['following' => true, 'followers_count' => $hasil['followers_count']];

        return $hasil['created']
            ? ApiResponse::success($data, 'Anda mengikuti toko ini.', 201)
            : ApiResponse::success($data, 'Anda sudah mengikuti toko ini.');
    }

    /** DELETE /api/v1/stores/{slug}/follow */
    public function unfollow(Request $request, string $slug): JsonResponse
    {
        return ApiResponse::success($this->follows->unfollow($request->user(), $slug), 'Anda berhenti mengikuti toko ini.');
    }

    /** GET /api/v1/following */
    public function following(ListFollowsRequest $request): JsonResponse
    {
        $hasil = $this->follows->following($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Daftar toko yang diikuti berhasil diambil.',
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
        ]);
    }

    /** GET /api/v1/seller/followers */
    public function followers(ListFollowsRequest $request): JsonResponse
    {
        $toko  = Store::query()->where('owner_id', $request->user()->id)->firstOrFail();
        $hasil = $this->follows->followersOf($toko, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Daftar pengikut berhasil diambil.',
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
            'summary' => $hasil['summary'],
        ]);
    }
}
