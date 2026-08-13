<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenAIVisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionAnalysisController extends Controller
{
    public function __construct(
        private readonly OpenAIVisionService $vision,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'openai_configured' => $this->vision->isConfigured(),
            'features' => [
                'answer' => true,
                'explain' => false,
            ],
        ]);
    }

    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'string'],
            'mime_type' => ['nullable', 'string', 'in:image/png,image/jpeg,image/webp'],
            'seen_questions' => ['nullable', 'array'],
            'seen_questions.*' => ['string', 'max:500'],
        ]);

        if (! $this->vision->isConfigured()) {
            return response()->json([
                'message' => 'OpenAI is not configured on the server.',
            ], 503);
        }

        try {
            $result = $this->vision->analyzeQuestions(
                $data['image'],
                $data['mime_type'] ?? 'image/png',
                $data['seen_questions'] ?? [],
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'mode' => 'answer',
            'summary' => $result['summary'],
            'questions' => $result['questions'],
            'question_count' => count($result['questions']),
        ]);
    }
}
