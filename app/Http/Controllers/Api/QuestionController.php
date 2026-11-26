<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\AskQuestionRequest;
use App\Http\Requests\Feedback\ListQuestionsRequest;
use App\Services\QuestionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class QuestionController extends Controller
{
    public function __construct(private readonly QuestionService $questions) {}

    /** POST /api/v1/products/{slug}/questions */
    public function ask(AskQuestionRequest $request, string $slug): JsonResponse
    {
        $q = $this->questions->ask($request->user(), $slug, $request->validated('question'));

        return ApiResponse::success($this->questions->present($q), 'Pertanyaan terkirim.', 201);
    }

    /** GET /api/v1/products/{slug}/questions (publik) */
    public function index(ListQuestionsRequest $request, string $slug): JsonResponse
    {
        $hasil = $this->questions->listPublic($slug, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Daftar tanya jawab berhasil diambil.',
            'data'    => $hasil['items'],
            'meta'    => $hasil['meta'],
        ]);
    }
}
