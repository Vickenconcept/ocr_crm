<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIVisionService
{
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Analyze a screenshot and extract questions with answers.
     *
     * @return array{questions: array<int, array<string, mixed>>, summary: string}
     */
    public function analyzeQuestions(string $imageBase64, string $mimeType = 'image/png', array $seenQuestions = []): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('OpenAI is not configured on the server.');
        }

        $imageUrl = $this->normalizeImageDataUrl($imageBase64, $mimeType);
        $prompt = $this->questionAnswerPrompt($seenQuestions);

        $headers = array_filter([
            'HTTP-Referer' => (string) config('services.openai.site_url', ''),
            'X-Title' => (string) config('services.openai.site_name', ''),
        ]);

        $response = Http::withToken($this->apiKey())
            ->withHeaders($headers)
            ->timeout(120)
            ->post($this->chatCompletionsUrl(), [
                'model' => (string) config('services.openai.vision_model', 'openai/gpt-4o'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a study assistant that reads screenshots and returns structured JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'high']],
                        ],
                    ],
                ],
                'max_tokens' => 4000,
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            Log::warning('[OpenAIVisionService] Vision request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('OpenAI vision request failed.');
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');

        if ($content === '') {
            throw new \RuntimeException('OpenAI returned an empty response.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('OpenAI returned invalid JSON.');
        }

        return $this->normalizeQuestionPayload($decoded);
    }

    /**
     * @param  array<int, string>  $seenQuestions
     */
    private function questionAnswerPrompt(array $seenQuestions = []): string
    {
        $seenBlock = '';

        if ($seenQuestions !== []) {
            $lines = collect($seenQuestions)
                ->map(fn ($question) => '- '.trim((string) $question))
                ->filter()
                ->take(30)
                ->implode("\n");

            $seenBlock = <<<SEEN

Already processed questions (SKIP these completely — do NOT return them again):
{$lines}

Only return NEW questions that are NOT in the list above.
If every visible question was already processed, return "questions": [].
SEEN;
        }

        return <<<PROMPT
Analyze the screenshot and extract every visible question for learning purposes.
{$seenBlock}

Return JSON with this exact shape:
{
  "summary": "One sentence describing what is on screen",
  "questions": [
    {
      "number": 1,
      "text": "Full question text copied exactly as shown",
      "type": "open|multiple_choice|true_false|math|code|visual_pattern|graph|diagram|other",
      "options": ["A. ...", "B. ..."],
      "answer": "Direct concise answer",
      "speech": "Natural spoken script, e.g. Question one. What is polymorphism? The answer is ..."
    }
  ]
}

Rules:
- You receive a SCREENSHOT IMAGE — read BOTH text AND visuals (not text-only).
- Include numbered questions in reading order.
- Copy each question's text EXACTLY as it appears on screen (word-for-word).
- For multiple choice, include all visible options and identify the best answer.
- For visual pattern questions (e.g. 4 images/shapes in a sequence, "what comes next?", odd one out):
  - Describe what you see in the pattern briefly in "text" if there is little written text.
  - Analyze shapes, colors, rotation, count, position, and progression.
  - Set type to "visual_pattern" and give the correct option or next pattern in "answer".
- For graphs, charts, and diagrams: read axes, labels, values, and trends. Set type to "graph" or "diagram".
- For math/code, solve or explain the answer clearly.
- Ignore ads, navigation chrome, and unrelated UI text.
- Do not repeat questions that were already processed.
- If no NEW questions are found, return "questions": [] and explain in summary.
- Keep speech natural for text-to-speech.
- Return JSON only.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{questions: array<int, array<string, mixed>>, summary: string}
     */
    private function normalizeQuestionPayload(array $payload): array
    {
        $questions = collect($payload['questions'] ?? [])
            ->filter(fn ($question) => is_array($question) && filled($question['text'] ?? null))
            ->values()
            ->map(function (array $question, int $index): array {
                $number = (int) ($question['number'] ?? ($index + 1));
                $text = trim((string) $question['text']);
                $answer = trim((string) ($question['answer'] ?? ''));
                $speech = trim((string) ($question['speech'] ?? ''));

                if ($speech === '') {
                    $speech = "Question {$number}. {$text}. The answer is {$answer}.";
                }

                return [
                    'number' => $number,
                    'text' => $text,
                    'type' => (string) ($question['type'] ?? 'other'),
                    'options' => array_values(array_filter(
                        (array) ($question['options'] ?? []),
                        fn ($option) => is_string($option) && $option !== '',
                    )),
                    'answer' => $answer,
                    'speech' => $speech,
                ];
            })
            ->all();

        return [
            'summary' => trim((string) ($payload['summary'] ?? 'Analysis complete.')),
            'questions' => $questions,
        ];
    }

    private function normalizeImageDataUrl(string $imageBase64, string $mimeType): string
    {
        if (str_starts_with($imageBase64, 'data:image/')) {
            return $imageBase64;
        }

        $clean = preg_replace('/\s+/', '', $imageBase64) ?? $imageBase64;

        return "data:{$mimeType};base64,{$clean}";
    }

    private function apiKey(): string
    {
        return (string) config('services.openai.key', '');
    }

    private function chatCompletionsUrl(): string
    {
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://openrouter.ai/api/v1'), '/');

        return "{$baseUrl}/chat/completions";
    }
}
