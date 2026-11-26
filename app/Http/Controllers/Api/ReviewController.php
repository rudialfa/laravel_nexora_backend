<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\CreateReviewRequest;
use App\Http\Requests\Feedback\CreateStoreReviewRequest;
use App\Http\Requests\Feedback\ListReviewsRequest;
use App\Services\ReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /** GET /api/v1/reviews/pending */
    public function pending(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reviews->pending($request->user()));
    }

    /** POST /api/v1/orders/{order}/reviews */
    public function storeProduct(CreateReviewRequest $request, string $order): JsonResponse
    {
        $foto = $request->file('photos');

        $review = $this->reviews->createProductReview(
            $request->user(),
            $order,
            $request->validated(),
            is_array($foto) ? $foto : []
        );

        return ApiResponse::success($this->reviews->presentReview($review), 'Ulasan berhasil dikirim.', 201);
    }

    /** POST /api/v1/orders/{order}/store-review */
    public function storeStore(CreateStoreReviewRequest $request, string $order): JsonResponse
    {
        $review = $this->reviews->createStoreReview($request->user(), $order, $request->validated());

        return ApiResponse::success($this->reviews->presentStoreReview($review), 'Ulasan toko berhasil dikirim.', 201);
    }

    /** GET /api/v1/products/{slug}/reviews (publik) */
    public function productReviews(ListReviewsRequest $request, string $slug): JsonResponse
    {
        $hasil = $this->reviews->listProductReviews($slug, $request->validated());

        return $this->daftar('Daftar ulasan berhasil diambil.', $hasil);
    }

    /** GET /api/v1/stores/{slug}/reviews (publik) */
    public function storeReviews(ListReviewsRequest $request, string $slug): JsonResponse
    {
        $hasil = $this->reviews->listStoreReviews($slug, $request->validated());

        return $this->daftar('Daftar ulasan toko berhasil diambil.', $hasil);
    }

    /** @param  array{items: array<int, array<string, mixed>>, meta: array<string, int>, summary: array<string, mixed>}  $hasil */
    private function daftar(string $pesan, array $hasil): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $pesan,
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
            'summary' => $hasil['summary'],
        ]);
    }
}
