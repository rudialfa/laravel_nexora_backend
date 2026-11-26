<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\AnswerQuestionRequest;
use App\Http\Requests\Feedback\ListQuestionsRequest;
use App\Http\Requests\Feedback\ListReviewsRequest;
use App\Http\Requests\Feedback\ReplyReviewRequest;
use App\Models\Store;
use App\Services\QuestionService;
use App\Services\ReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ulasan dan pertanyaan pada produk toko seller yang login. Milik toko lain dijawab 404. */
class SellerFeedbackController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly QuestionService $questions,
    ) {}

    /** GET /api/v1/seller/reviews */
    public function reviews(ListReviewsRequest $request): JsonResponse
    {
        $hasil = $this->reviews->listForSeller($this->toko($request), $request->validated());

        return $this->daftar('Daftar ulasan berhasil diambil.', $hasil);
    }

    /** POST /api/v1/seller/reviews/{review}/reply */
    public function reply(ReplyReviewRequest $request, string $review): JsonResponse
    {
        $r = $this->reviews->reply($this->toko($request), $review, $request->validated('reply'));

        return ApiResponse::success($this->reviews->presentReview($r, true), 'Balasan terkirim.');
    }

    /** GET /api/v1/seller/questions */
    public function questions(ListQuestionsRequest $request): JsonResponse
    {
        $hasil = $this->questions->listForSeller($this->toko($request), $request->validated());

        return $this->daftar('Daftar pertanyaan berhasil diambil.', $hasil);
    }

    /** POST /api/v1/seller/questions/{question}/answer */
    public function answer(AnswerQuestionRequest $request, string $question): JsonResponse
    {
        $q = $this->questions->answer($this->toko($request), $question, $request->user(), $request->validated('answer'));

        return ApiResponse::success($this->questions->present($q, $this->toko($request)->name, true), 'Jawaban terkirim.');
    }

    /** @param  array{items: array<int, array<string, mixed>>, meta: array<string, int>}  $hasil */
    private function daftar(string $pesan, array $hasil): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $pesan, 'data' => $hasil['items'], 'meta' => $hasil['meta']]);
    }

    private function toko(Request $request): Store
    {
        return Store::query()->where('owner_id', $request->user()->id)->firstOrFail();
    }
}
