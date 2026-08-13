<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuestionAnalysisTest extends TestCase
{
    public function test_status_requires_api_key(): void
    {
        config(['services.ocr.api_key' => 'secret-key']);

        $this->getJson('/api/v1/status')
            ->assertUnauthorized();
    }

    public function test_status_returns_configuration_state(): void
    {
        config([
            'services.ocr.api_key' => 'secret-key',
            'services.openai.key' => 'openai-key',
        ]);

        $this->withHeader('X-OCR-API-Key', 'secret-key')
            ->getJson('/api/v1/status')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('openai_configured', true)
            ->assertJsonPath('features.answer', true);
    }

    public function test_answer_endpoint_returns_structured_questions(): void
    {
        config([
            'services.ocr.api_key' => 'secret-key',
            'services.openai.key' => 'openai-key',
        ]);

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Two practice questions.',
                            'questions' => [[
                                'number' => 1,
                                'text' => 'What is the capital of France?',
                                'type' => 'open',
                                'options' => [],
                                'answer' => 'Paris',
                                'speech' => 'Question one. What is the capital of France? The answer is Paris.',
                            ]],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200),
        ]);

        $this->withHeader('X-OCR-API-Key', 'secret-key')
            ->postJson('/api/v1/analyze/answer', [
                'image' => base64_encode('fake-image'),
                'mime_type' => 'image/png',
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'answer')
            ->assertJsonPath('question_count', 1)
            ->assertJsonPath('questions.0.answer', 'Paris');
    }
}
