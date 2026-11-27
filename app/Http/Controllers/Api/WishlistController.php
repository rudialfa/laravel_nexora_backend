<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\AddWishlistRequest;
use App\Http\Requests\Social\CheckWishlistRequest;
use App\Http\Requests\Social\ListWishlistRequest;
use App\Services\WishlistService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function __construct(private readonly WishlistService $wishlist) {}

    /** GET /api/v1/wishlist */
    public function index(ListWishlistRequest $request): JsonResponse
    {
        $hasil = $this->wishlist->list($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Wishlist berhasil diambil.',
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
        ]);
    }

    /** GET /api/v1/wishlist/check */
    public function check(CheckWishlistRequest $request): JsonResponse
    {
        return ApiResponse::success($this->wishlist->check($request->user(), $request->validated('product_ids')));
    }

    /** POST /api/v1/wishlist */
    public function store(AddWishlistRequest $request): JsonResponse
    {
        $hasil = $this->wishlist->add($request->user(), $request->validated('product_id'));
        $data  = ['in_wishlist' => true, 'count' => $hasil['count']];

        return $hasil['created']
            ? ApiResponse::success($data, 'Produk ditambahkan ke wishlist.', 201)
            : ApiResponse::success($data, 'Produk sudah ada di wishlist.');
    }

    /** DELETE /api/v1/wishlist/{product} */
    public function destroy(Request $request, string $product): JsonResponse
    {
        $jumlah = $this->wishlist->remove($request->user(), $product);

        return ApiResponse::success(['in_wishlist' => false, 'count' => $jumlah], 'Produk dihapus dari wishlist.');
    }
}
