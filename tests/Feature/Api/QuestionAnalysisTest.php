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
            ->assertJsonPath('features.speech', true)
            ->assertJsonPath('features.code', true);
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

    public function test_code_endpoint_returns_formatted_solution(): void
    {
        config([
            'services.ocr.api_key' => 'secret-key',
            'services.openai.key' => 'openai-key',
        ]);

        $code = <<<'JS'
const N = 10;
for (let i = 1; i <= N; i++) {
  console.log(" ".repeat(N - i) + "*".repeat(2 * i - 1));
}
JS;

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Print a 10-level asterisk pyramid.',
                            'questions' => [[
                                'number' => 1,
                                'text' => 'Render a pyramid with N=10 asterisk rows.',
                                'type' => 'code',
                                'language' => 'javascript',
                                'filename' => 'main.js',
                                'diagnosis' => 'Starter Hello world is still in the editor.',
                                'code' => $code,
                                'answer' => 'Loop 10 rows; pad spaces then print an odd number of stars.',
                                'speech' => 'Print ten rows of stars, adding two stars each row and centering them with spaces.',
                            ]],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200),
        ]);

        $this->withHeader('X-OCR-API-Key', 'secret-key')
            ->postJson('/api/v1/analyze/code', [
                'image' => base64_encode('fake-image'),
                'mime_type' => 'image/png',
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'code')
            ->assertJsonPath('question_count', 1)
            ->assertJsonPath('questions.0.type', 'code')
            ->assertJsonPath('questions.0.language', 'javascript')
            ->assertJsonPath('questions.0.filename', 'main.js')
            ->assertJsonPath('questions.0.diagnosis', 'Starter Hello world is still in the editor.')
            ->assertJsonPath('questions.0.code', $code);
    }

    public function test_code_endpoint_strips_highlight_markup_and_uses_previous_attempt(): void
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
                            'summary' => 'The run failed because highlight HTML was pasted.',
                            'questions' => [[
                                'number' => 1,
                                'text' => 'Render a pyramid with N=10 asterisk rows.',
                                'type' => 'code',
                                'language' => 'javascript',
                                'filename' => 'main.js',
                                'diagnosis' => 'SyntaxError from leftover tok-str HTML in line 3.',
                                'code' => 'let spaces = <span class="tok-str">\' \'</span>.repeat(N - i - 1);',
                                'answer' => 'Replace the file with clean source. Do not copy highlighted HTML.',
                                'speech' => 'The error is leftover highlight HTML. Paste the clean code.',
                            ]],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200),
        ]);

        $this->withHeader('X-OCR-API-Key', 'secret-key')
            ->postJson('/api/v1/analyze/code', [
                'image' => base64_encode('fake-image'),
                'mime_type' => 'image/png',
                'previous_attempt' => [
                    'text' => 'Render a pyramid with N=10 asterisk rows.',
                    'code' => 'generatePyramid(10);',
                    'diagnosis' => 'First pass.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('questions.0.diagnosis', 'SyntaxError from leftover tok-str HTML in line 3.')
            ->assertJsonPath('questions.0.code', "let spaces = ' '.repeat(N - i - 1);");

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/chat/completions')) {
                return false;
            }

            $text = (string) data_get($request->data(), 'messages.1.content.0.text', '');

            return str_contains($text, 'generatePyramid(10);')
                && str_contains($text, 'Do NOT skip this screenshot')
                && str_contains($text, 'OUTPUT, ERRORS, TESTS');
        });
    }

    public function test_code_endpoint_dictates_when_stuck(): void
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
                            'summary' => 'Still stuck on the loop line.',
                            'questions' => [[
                                'number' => 1,
                                'text' => 'Render a pyramid with N=10 asterisk rows.',
                                'type' => 'code',
                                'language' => 'javascript',
                                'filename' => 'main.js',
                                'diagnosis' => 'Function exists but loop body is missing.',
                                'next_step' => "let spaces = ' '.repeat(N - i - 1);",
                                'dictation' => "let spaces equals single quote space single quote dot repeat open paren N minus i minus 1 close paren semicolon",
                                'code' => "function generatePyramid(N) {\n  for (let i = 0; i < N; i++) {\n    let spaces = ' '.repeat(N - i - 1);\n  }\n}",
                                'answer' => 'Type the spaces line inside the loop.',
                                'speech' => 'Type the spaces line now.',
                            ]],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200),
        ]);

        $this->withHeader('X-OCR-API-Key', 'secret-key')
            ->postJson('/api/v1/analyze/code', [
                'image' => base64_encode('fake-image'),
                'mime_type' => 'image/png',
                'previous_attempt' => [
                    'text' => 'Render a pyramid with N=10 asterisk rows.',
                    'code' => "function generatePyramid(N) {\n}",
                    'diagnosis' => 'Still missing loop body.',
                    'stuck_count' => 2,
                    'dictate_line_index' => 1,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('questions.0.dictation', 'let spaces equals single quote space single quote dot repeat open paren N minus i minus 1 close paren semicolon')
            ->assertJsonPath('questions.0.speech', 'let spaces equals single quote space single quote dot repeat open paren N minus i minus 1 close paren semicolon');

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/chat/completions')) {
                return false;
            }

            $text = (string) data_get($request->data(), 'messages.1.content.0.text', '');

            return str_contains($text, 'STUCK COACH MODE')
                && str_contains($text, 'DICTATION');
        });
    }
}
