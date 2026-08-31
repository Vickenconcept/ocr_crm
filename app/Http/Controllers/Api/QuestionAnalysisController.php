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
                'speech' => true,
                'explain' => false,
                'code' => true,
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
            'profile' => ['nullable', 'array'],
            'profile.resume' => ['nullable', 'string', 'max:20000'],
            'profile.question_context' => ['nullable', 'string', 'max:8000'],
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
                $data['profile'] ?? [],
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

    public function code(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'string'],
            'mime_type' => ['nullable', 'string', 'in:image/png,image/jpeg,image/webp'],
            'seen_questions' => ['nullable', 'array'],
            'seen_questions.*' => ['string', 'max:500'],
            'profile' => ['nullable', 'array'],
            'profile.resume' => ['nullable', 'string', 'max:20000'],
            'profile.question_context' => ['nullable', 'string', 'max:8000'],
        ]);

        if (! $this->vision->isConfigured()) {
            return response()->json([
                'message' => 'OpenAI is not configured on the server.',
            ], 503);
        }

        try {
            $result = $this->vision->analyzeCode(
                $data['image'],
                $data['mime_type'] ?? 'image/png',
                $data['seen_questions'] ?? [],
                $data['profile'] ?? [],
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'mode' => 'code',
            'summary' => $result['summary'],
            'questions' => $result['questions'],
            'question_count' => count($result['questions']),
        ]);
    }

    public function speech(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audio' => ['required', 'string'],
            'mime_type' => ['nullable', 'string', 'max:80'],
            'seen_questions' => ['nullable', 'array'],
            'seen_questions.*' => ['string', 'max:500'],
            'image' => ['nullable', 'string'],
            'image_mime_type' => ['nullable', 'string', 'in:image/png,image/jpeg,image/webp'],
            'profile' => ['nullable', 'array'],
            'profile.resume' => ['nullable', 'string', 'max:20000'],
            'profile.question_context' => ['nullable', 'string', 'max:8000'],
        ]);

        if (! $this->vision->isConfigured()) {
            return response()->json([
                'message' => 'OpenAI is not configured on the server.',
            ], 503);
        }

        try {
            $result = $this->vision->analyzeSpokenQuestions(
                $data['audio'],
                $data['mime_type'] ?? 'audio/webm',
                $data['seen_questions'] ?? [],
                $data['image'] ?? null,
                $data['image_mime_type'] ?? 'image/jpeg',
                $data['profile'] ?? [],
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'mode' => 'speech',
            'transcript' => $result['transcript'],
            'summary' => $result['summary'],
            'questions' => $result['questions'],
            'question_count' => count($result['questions']),
        ]);
    }
}
