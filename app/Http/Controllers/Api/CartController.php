<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\SelectCartItemsRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Services\CartService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    /** GET /api/v1/cart */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->cart->view($request->user()));
    }

    /** POST /api/v1/cart/items */
    public function store(AddCartItemRequest $request): JsonResponse
    {
        $baru = $this->cart->add(
            $request->user(),
            $request->validated('variant_id'),
            (int) ($request->validated('quantity') ?? 1)
        );

        return ApiResponse::success(
            $this->cart->view($request->user()),
            $baru ? 'Produk ditambahkan ke keranjang.' : 'Jumlah produk di keranjang diperbarui.',
            $baru ? 201 : 200
        );
    }

    /** PATCH /api/v1/cart/items/{variant} */
    public function update(UpdateCartItemRequest $request, string $variant): JsonResponse
    {
        $this->cart->update($request->user(), $variant, $request->validated());

        return ApiResponse::success($this->cart->view($request->user()), 'Keranjang diperbarui.');
    }

    /** DELETE /api/v1/cart/items/{variant} */
    public function destroy(Request $request, string $variant): JsonResponse
    {
        $this->cart->remove($request->user(), $variant);

        return ApiResponse::success($this->cart->view($request->user()), 'Produk dihapus dari keranjang.');
    }

    /** POST /api/v1/cart/select */
    public function select(SelectCartItemsRequest $request): JsonResponse
    {
        $this->cart->select(
            $request->user(),
            $request->validated('variant_ids'),
            $request->boolean('selected')
        );

        return ApiResponse::success($this->cart->view($request->user()), 'Pilihan keranjang diperbarui.');
    }

    /** DELETE /api/v1/cart */
    public function clear(Request $request): JsonResponse
    {
        $this->cart->clear($request->user());

        return ApiResponse::success($this->cart->view($request->user()), 'Keranjang dikosongkan.');
    }
}
