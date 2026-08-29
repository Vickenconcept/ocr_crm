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
            ->assertJsonPath('features.answer', true)
            ->assertJsonPath('features.speech', true);
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

    public function test_answer_endpoint_forwards_resume_and_question_context(): void
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
                            'summary' => 'One interview question.',
                            'questions' => [[
                                'number' => 1,
                                'text' => 'Tell me about yourself.',
                                'type' => 'open',
                                'options' => [],
                                'answer' => 'I am a Laravel developer.',
                                'speech' => 'I am a Laravel developer with CRM experience.',
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
                'profile' => [
                    'resume' => 'Jane Doe, Senior Laravel developer, 6 years, CRM and queues.',
                    'question_context' => 'Laravel interview. Expect Eloquent, queues, and STAR behavioral questions.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('question_count', 1);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/chat/completions')) {
                return false;
            }

            $payload = $request->data();
            $text = (string) data_get($payload, 'messages.1.content.0.text', '');

            return str_contains($text, 'Jane Doe, Senior Laravel developer')
                && str_contains($text, 'Laravel interview. Expect Eloquent');
        });
    }

    public function test_speech_endpoint_transcribes_and_answers_spoken_questions(): void
    {
        config([
            'services.ocr.api_key' => 'secret-key',
            'services.openai.key' => 'openai-key',
        ]);

        Http::fake([
            'openrouter.ai/api/v1/audio/transcriptions' => Http::response([
                'text' => 'What is polymorphism in object-oriented programming?',
            ], 200),
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'A spoken OOP question.',
                            'questions' => [[
                                'number' => 1,
                                'text' => 'What is polymorphism in object-oriented programming?',
                                'type' => 'open',
                                'options' => [],
                                'answer' => 'The ability of different objects to respond to the same message in their own way.',
                                'speech' => 'Polymorphism is when different objects respond to the same message in their own way.',
                            ]],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200),
        ]);

        $this->withHeader('X-OCR-API-Key', 'secret-key')
            ->postJson('/api/v1/analyze/speech', [
                'audio' => base64_encode('fake-audio'),
                'mime_type' => 'audio/webm',
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'speech')
            ->assertJsonPath('question_count', 1)
            ->assertJsonPath('transcript', 'What is polymorphism in object-oriented programming?')
            ->assertJsonPath('questions.0.answer', 'The ability of different objects to respond to the same message in their own way.');
    }
}
