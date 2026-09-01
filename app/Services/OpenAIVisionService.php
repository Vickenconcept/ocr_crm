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
     * @param  array<int, string>  $seenQuestions
     * @param  array{resume?: string, question_context?: string}  $profile
     * @return array{questions: array<int, array<string, mixed>>, summary: string}
     */
    public function analyzeQuestions(string $imageBase64, string $mimeType = 'image/png', array $seenQuestions = [], array $profile = []): array
    {
        $imageUrl = $this->normalizeImageDataUrl($imageBase64, $mimeType);

        return $this->requestChatJson(
            'You are an interview and study assistant that reads screenshots and returns structured JSON only.',
            [
                ['type' => 'text', 'text' => $this->questionAnswerPrompt($seenQuestions, $profile)],
                ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'high']],
            ],
            'OpenAI vision request failed.',
        );
    }

    /**
     * Analyze a coding-assessment screenshot and return a formatted solution.
     *
     * @param  array<int, string>  $seenQuestions
     * @param  array{resume?: string, question_context?: string}  $profile
     * @param  array{text?: string, code?: string, diagnosis?: string, step_mode?: bool, repeat_count?: int, step_index?: int}  $previousAttempt
     * @return array{questions: array<int, array<string, mixed>>, summary: string}
     */
    public function analyzeCode(
        string $imageBase64,
        string $mimeType = 'image/png',
        array $seenQuestions = [],
        array $profile = [],
        array $previousAttempt = [],
    ): array {
        $imageUrl = $this->normalizeImageDataUrl($imageBase64, $mimeType);

        return $this->requestChatJson(
            'You are a live coding coach. Read the CURRENT editor and errors, say where the candidate is, tell the next step out loud, and return a clean full solution as JSON only.',
            [
                ['type' => 'text', 'text' => $this->codeHelpPrompt($seenQuestions, $profile, $previousAttempt)],
                ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'high']],
            ],
            'OpenAI coding analysis request failed.',
            null,
            5000,
        );
    }

    /**
     * Transcribe meeting audio, then answer any spoken questions (interview, study, or technical).
     *
     * @param  array<int, string>  $seenQuestions
     * @param  array{resume?: string, question_context?: string}  $profile
     * @return array{transcript: string, questions: array<int, array<string, mixed>>, summary: string}
     */
    public function analyzeSpokenQuestions(
        string $audioBase64,
        string $audioMimeType = 'audio/webm',
        array $seenQuestions = [],
        ?string $imageBase64 = null,
        string $imageMimeType = 'image/jpeg',
        array $profile = [],
    ): array {
        $transcript = $this->transcribeAudio($audioBase64, $audioMimeType);

        if (mb_strlen($transcript) < 8) {
            return [
                'transcript' => $transcript,
                'summary' => 'No clear speech was heard.',
                'questions' => [],
            ];
        }

        $userContent = [
            ['type' => 'text', 'text' => $this->spokenQuestionPrompt($transcript, $seenQuestions, $profile)],
        ];

        if (filled($imageBase64) && ! $this->looksLikeSpokenQuestion($transcript)) {
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $this->normalizeImageDataUrl($imageBase64, $imageMimeType),
                    'detail' => 'low',
                ],
            ];
        }

        $result = $this->requestChatJson(
            'You are a live interview and study assistant. If a question was asked, answer it immediately. Return structured JSON only.',
            $userContent,
            'OpenAI speech analysis request failed.',
            $this->speechModel(),
            1200,
        );

        if ($result['questions'] === [] && $this->looksLikeSpokenQuestion($transcript)) {
            $result = $this->requestChatJson(
                'A question was asked. Answer it now. Return structured JSON only.',
                $this->spokenQuestionPrompt($transcript, [], $profile),
                'OpenAI speech analysis request failed.',
                $this->speechModel(),
                1200,
            );
        }

        return [
            'transcript' => $transcript,
            'summary' => $result['summary'],
            'questions' => $result['questions'],
        ];
    }

    public function transcribeAudio(string $audioBase64, string $mimeType = 'audio/webm'): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('OpenAI is not configured on the server.');
        }

        $binary = $this->decodeBase64Payload($audioBase64);

        if ($binary === '') {
            throw new \RuntimeException('Audio payload was empty.');
        }

        $response = Http::withToken($this->apiKey())
            ->withHeaders($this->providerHeaders())
            ->timeout(45)
            ->attach('file', $binary, $this->audioFilename($mimeType))
            ->post($this->audioTranscriptionsUrl(), [
                'model' => $this->transcribeModel(),
                'response_format' => 'json',
            ]);

        if (! $response->successful()) {
            Log::warning('[OpenAIVisionService] Transcription request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Speech transcription failed.');
        }

        return trim((string) ($response->json('text') ?? ''));
    }

    /**
     * @param  array<int, array<string, mixed>>|string  $userContent
     * @return array{questions: array<int, array<string, mixed>>, summary: string}
     */
    private function requestChatJson(
        string $system,
        array|string $userContent,
        string $failureMessage,
        ?string $model = null,
        int $maxTokens = 4000,
    ): array {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('OpenAI is not configured on the server.');
        }

        $response = Http::withToken($this->apiKey())
            ->withHeaders($this->providerHeaders())
            ->timeout(45)
            ->post($this->chatCompletionsUrl(), [
                'model' => $model ?: (string) config('services.openai.vision_model', 'openai/gpt-4o'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $system,
                    ],
                    [
                        'role' => 'user',
                        'content' => $userContent,
                    ],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            Log::warning('[OpenAIVisionService] Chat request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException($failureMessage);
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
     * @param  array{resume?: string, question_context?: string}  $profile
     */
    private function questionAnswerPrompt(array $seenQuestions = [], array $profile = []): string
    {
        $seenBlock = $this->seenQuestionsBlock($seenQuestions);
        $learnerBlock = $this->learnerContextBlock($profile);

        return <<<PROMPT
Analyze the screenshot and extract every visible question (interview, exam, or study).
{$seenBlock}
{$learnerBlock}

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
- If learner context is provided, tailor answers to that person and the expected question types.
- Return JSON only.
PROMPT;
    }

    /**
     * @param  array<int, string>  $seenQuestions
     * @param  array{resume?: string, question_context?: string}  $profile
     * @param  array{text?: string, code?: string, diagnosis?: string, step_mode?: bool, repeat_count?: int, step_index?: int}  $previousAttempt
     */
    private function codeHelpPrompt(array $seenQuestions = [], array $profile = [], array $previousAttempt = []): string
    {
        $learnerBlock = $this->learnerContextBlock($profile);
        $previousBlock = $this->previousCodingAttemptBlock($previousAttempt);
        $stepBlock = $this->stepByStepBlock($previousAttempt);

        return <<<PROMPT
This is a LIVE coaching pass. The popup may be closed. The candidate is writing in the editor right now.
Coach them piece by piece — like a tutor sitting beside them, not a lecture.
Understand the CURRENT STATE of the whole screen, not only the problem title.
{$learnerBlock}
{$previousBlock}
{$stepBlock}

Read EVERY visible panel:
1. Problem / description / examples / constraints
2. Editor: language, filename, and the code that is actually there now — what is already typed, what is missing
3. OUTPUT, ERRORS, TESTS, console, or diff — including red error text

Return JSON with this exact shape:
{
  "summary": "One sentence: where they are right now (blank editor, mid-solution, error, or done)",
  "questions": [
    {
      "number": 1,
      "text": "Short restatement of the task",
      "type": "code",
      "language": "javascript",
      "filename": "main.js",
      "diagnosis": "Where they are: what is already written, what is wrong or missing",
      "place": "Where in the file to work next, e.g. inside the function, inside the loop, at the bottom",
      "snippet": "The exact small piece of code to add or change right now — one statement or line",
      "next_step": "Same as speech: the single next piece to type",
      "code": "complete CLEAN working program using real newlines and indentation. Plain source only.",
      "answer": "Brief why this piece comes next",
      "speech": "2 to 4 short conversational sentences. Name the place, then the code. Example: Inside the function, add: const spaces = ' '.repeat(N - i - 1);"
    }
  ]
}

Rules:
- You receive a SCREENSHOT — read problem + editor + errors/output together.
- NEVER skip because this problem was seen before. Recapture means they typed, ran, or hit an error. Analyze the new state.
- Coach from the CURRENT editor, not from a blank file. If they already have a loop, do not pretend they have nothing.
- If ERRORS / SyntaxError / failed tests are visible, lead with that in summary, diagnosis, next_step, answer, and speech.
- Compare the editor text with the error snippet. If the error shows extra tokens (HTML, class=, tok-str, span tags, leftover markdown fences), the file has corrupted paste — say that and return CLEAN source.
- "code" must be plain source they can paste. NEVER include HTML, CSS classes, markdown fences, or syntax-highlight markup.
- Match required I/O exactly (print vs return, function name, N value, stdout format).
- If the visible logic is already correct and only paste/syntax junk broke the run, keep the same algorithm and strip the junk.
- Coach ONE piece at a time. Wait for them to type it before moving on. Compare the editor on this screenshot with the last pass.
- If they already typed the last suggested piece, give the NEXT piece only — do not repeat what is already there.
- speech and next_step must sound conversational: name the place, then the code. Examples:
  - "At the top of the file, add: function generatePyramid(N) {"
  - "Inside the function, add the loop: for (let i = 0; i < N; i++) {"
  - "Inside the loop, add: let spaces = ' '.repeat(N - i - 1);"
  - "At the bottom, call it with: generatePyramid(10);"
- place = where. snippet = the exact code for this step only. speech = place + snippet spoken naturally.
- Do NOT dump the whole solution in speech. One piece per response unless they are completely stuck.
- If the editor is still starter boilerplate, give the first lines to type and still include the full solution in "code".
- If the solution already looks complete and output matches, say they are done and keep speech very short.
- If STEP-BY-STEP MODE is active, they need even smaller pieces. One line or one statement at a time.
- Ignore ads, timers, and unrelated chrome.
- If no coding task is visible, return "questions": [] and explain in summary.
- Return JSON only.
PROMPT;
    }

    /**
     * @param  array{text?: string, code?: string, diagnosis?: string}  $previousAttempt
     */
    private function previousCodingAttemptBlock(array $previousAttempt): string
    {
        $text = trim((string) ($previousAttempt['text'] ?? ''));
        $code = trim((string) ($previousAttempt['code'] ?? ''));
        $diagnosis = trim((string) ($previousAttempt['diagnosis'] ?? ''));

        if ($text === '' && $code === '' && $diagnosis === '') {
            return '';
        }

        $code = $this->normalizeCodeString($code);

        return <<<BLOCK

A solution was already suggested earlier in this session. Do NOT skip this screenshot.
The candidate captured again after editing or running. Treat this as a NEW screen state.

Previous task: {$text}
Previous diagnosis: {$diagnosis}
Previous suggested code:
{$code}

Look at what is on screen NOW. If it failed, explain the error and return a clean fixed program.
BLOCK;
    }

    /**
     * @param  array{text?: string, code?: string, diagnosis?: string, step_mode?: bool, repeat_count?: int, step_index?: int}  $previousAttempt
     */
    private function stepByStepBlock(array $previousAttempt): string
    {
        $stepMode = (bool) ($previousAttempt['step_mode'] ?? false);
        $repeatCount = (int) ($previousAttempt['repeat_count'] ?? 0);
        $stepIndex = (int) ($previousAttempt['step_index'] ?? 0);

        if (! $stepMode && $repeatCount < 3) {
            return '';
        }

        return <<<BLOCK

STEP-BY-STEP MODE — the candidate is writing live and needs one small piece at a time.
Talk like you are pair-programming: say WHERE, then WHAT code goes there.

Rules for this pass:
- Give only the next missing piece — one statement, one line, or one brace
- If their editor already has the last piece you suggested, move to the next one
- speech example: "Inside the loop, add: let stars = '*'.repeat(2 * i + 1);"
- Current step progress index: {$stepIndex}
- Repeat count (they may be stuck): {$repeatCount}
BLOCK;
    }

    /**
     * @param  array<int, string>  $seenQuestions
     * @param  array{resume?: string, question_context?: string}  $profile
     */
    private function spokenQuestionPrompt(string $transcript, array $seenQuestions = [], array $profile = []): string
    {
        $seenBlock = $this->seenQuestionsBlock($seenQuestions);
        $learnerBlock = $this->learnerContextBlock($profile);

        return <<<PROMPT
You are helping a candidate during a LIVE interview, oral exam, or study session.
A transcript of recent speech is below. A video frame of the meeting may also be attached.
{$seenBlock}
{$learnerBlock}

Transcript:
"""
{$transcript}
"""

Return JSON with this exact shape:
{
  "summary": "One sentence describing what was asked or discussed",
  "questions": [
    {
      "number": 1,
      "text": "The question that was asked, cleaned up",
      "type": "open|multiple_choice|true_false|math|code|visual_pattern|graph|diagram|behavioral|other",
      "options": ["A. ...", "B. ..."],
      "answer": "Direct concise answer",
      "speech": "Natural spoken reply the candidate can hear"
    }
  ]
}

Rules:
- Skip already-processed questions ONLY when the topic/entity is the same (e.g. repeating "MCP server"). "What do you know about X?" vs "What do you know about Y?" are DIFFERENT questions — answer Y.
- If ANY real question was asked, answer it NOW. Include interview, technical, behavioral, study, and follow-up questions.
- If the transcript contains a question, you MUST return at least one item in "questions" with a real answer. Never return "No question was asked" when the transcript asks something.
- Ignore only non-questions: greetings, "can you hear me?", "next slide", "okay", "um", "yeah".
- Do not invent questions that were not asked.
- If there is truly no question, return "questions": [] and set summary to "No question was asked."
- Keep "speech" conversational, concise, and ready for text-to-speech. For interview/behavioral questions, answer in first person using the resume when provided.
- If learner context is provided, tailor answers to that person and the expected question types.
- Return JSON only.
PROMPT;
    }

    /**
     * @param  array{resume?: string, question_context?: string}  $profile
     */
    private function learnerContextBlock(array $profile): string
    {
        $resume = trim((string) ($profile['resume'] ?? ''));
        $questionContext = trim((string) ($profile['question_context'] ?? ''));

        if ($resume === '' && $questionContext === '') {
            return '';
        }

        $parts = [];

        if ($resume !== '') {
            $parts[] = "Candidate resume (match their real skills, stack, seniority, and projects; do not invent experience):\n{$resume}";
        }

        if ($questionContext !== '') {
            $parts[] = "Expected questions / session focus (match this domain, difficulty, and answer style):\n{$questionContext}";
        }

        $body = implode("\n\n", $parts);

        return <<<BLOCK

Learner context — stay focused and personal:
{$body}

When answering with this context:
- Tailor explanations to this person's background and tools they actually use.
- Prefer the expected question style (technical interview, behavioral STAR, Laravel, system design, etc.).
- For "tell me about yourself / a time when…" questions, answer in first person using only facts from the resume.
- Keep answers concise and practical for this candidate.
- If a question is outside this focus, still answer it, but keep it brief.
BLOCK;
    }

    /**
     * @param  array<int, string>  $seenQuestions
     */
    private function seenQuestionsBlock(array $seenQuestions = []): string
    {
        if ($seenQuestions === []) {
            return '';
        }

        $lines = collect($seenQuestions)
            ->map(fn ($question) => '- '.trim((string) $question))
            ->filter()
            ->take(30)
            ->implode("\n");

        return <<<SEEN

Already processed questions (SKIP these completely — do NOT return them again):
{$lines}

Only return NEW questions that are NOT in the list above.
If every question was already processed, return "questions": [].
SEEN;
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
                $code = $this->normalizeCodeString((string) ($question['code'] ?? ''));

                if ($code === '') {
                    $code = $this->extractFencedCode($answer);
                }

                $language = strtolower(trim((string) ($question['language'] ?? '')));
                $filename = trim((string) ($question['filename'] ?? ''));
                $diagnosis = trim((string) ($question['diagnosis'] ?? ''));
                $nextStep = trim((string) ($question['next_step'] ?? ''));
                $place = trim((string) ($question['place'] ?? ''));
                $snippet = trim((string) ($question['snippet'] ?? ''));

                if ($speech === '') {
                    if ($place !== '' && $snippet !== '') {
                        $speech = "{$place}, add: {$snippet}";
                    } elseif ($nextStep !== '') {
                        $speech = $nextStep;
                    } else {
                        $speech = "Question {$number}. {$text}. The answer is {$answer}.";
                    }
                }

                return [
                    'number' => $number,
                    'text' => $text,
                    'type' => (string) ($question['type'] ?? ($code !== '' ? 'code' : 'other')),
                    'language' => $language,
                    'filename' => $filename,
                    'diagnosis' => $diagnosis,
                    'next_step' => $nextStep,
                    'place' => $place,
                    'snippet' => $snippet,
                    'code' => $code,
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

    private function normalizeCodeString(string $code): string
    {
        $code = str_replace(["\r\n", "\r"], "\n", trim($code));

        if ($code === '') {
            return '';
        }

        $fenced = $this->extractFencedCode($code);
        $code = $fenced !== '' ? $fenced : $code;

        return $this->stripHighlightMarkup($code);
    }

    private function stripHighlightMarkup(string $code): string
    {
        $code = preg_replace('/<\/?span[^>]*>/i', '', $code) ?? $code;
        $code = preg_replace('/class=["\']tok-[^"\']*["\']\s*>?/i', '', $code) ?? $code;
        $code = str_replace(['&quot;', '&#039;', '&lt;', '&gt;', '&amp;'], ['"', "'", '<', '>', '&'], $code);

        return trim($code);
    }

    private function extractFencedCode(string $text): string
    {
        if (preg_match('/```(?:[a-zA-Z0-9_+-]*)\s*\n([\s\S]*?)\n```/', $text, $matches) === 1) {
            return rtrim(str_replace(["\r\n", "\r"], "\n", $matches[1]));
        }

        return '';
    }

    private function normalizeImageDataUrl(string $imageBase64, string $mimeType): string
    {
        if (str_starts_with($imageBase64, 'data:image/')) {
            return $imageBase64;
        }

        $clean = preg_replace('/\s+/', '', $imageBase64) ?? $imageBase64;

        return "data:{$mimeType};base64,{$clean}";
    }

    private function decodeBase64Payload(string $payload): string
    {
        if (str_starts_with($payload, 'data:')) {
            $payload = (string) preg_replace('/^data:[^;]+;base64,/', '', $payload);
        }

        $decoded = base64_decode(preg_replace('/\s+/', '', $payload) ?? $payload, true);

        return $decoded === false ? '' : $decoded;
    }

    private function audioFilename(string $mimeType): string
    {
        $mime = strtolower(trim(explode(';', $mimeType)[0]));

        return match ($mime) {
            'audio/ogg', 'audio/ogg;codecs=opus' => 'clip.ogg',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'clip.m4a',
            'audio/mpeg', 'audio/mp3' => 'clip.mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'clip.wav',
            default => 'clip.webm',
        };
    }

    /**
     * @return array<string, string>
     */
    private function providerHeaders(): array
    {
        return array_filter([
            'HTTP-Referer' => (string) config('services.openai.site_url', ''),
            'X-Title' => (string) config('services.openai.site_name', ''),
        ]);
    }

    private function looksLikeSpokenQuestion(string $transcript): bool
    {
        $text = strtolower(trim($transcript));

        if ($text === '') {
            return false;
        }

        if (str_contains($text, '?')) {
            return true;
        }

        return (bool) preg_match(
            '/\b(what|why|how|when|where|who|which|tell me|explain|describe|walk me|do you know|can you|could you|have you|is there|are you|what about)\b/i',
            $text,
        );
    }

    private function speechModel(): string
    {
        $configured = trim((string) config('services.openai.speech_model', ''));

        if ($configured !== '') {
            return $configured;
        }

        return str_contains($this->openaiBaseUrl(), 'openrouter.ai')
            ? 'openai/gpt-4o-mini'
            : 'gpt-4o-mini';
    }

    private function transcribeModel(): string
    {
        $configured = trim((string) config('services.openai.transcribe_model', ''));

        if ($configured !== '') {
            return $configured;
        }

        return str_contains($this->openaiBaseUrl(), 'openrouter.ai')
            ? 'openai/whisper-1'
            : 'whisper-1';
    }

    private function apiKey(): string
    {
        return (string) config('services.openai.key', '');
    }

    private function openaiBaseUrl(): string
    {
        return rtrim((string) config('services.openai.base_url', 'https://openrouter.ai/api/v1'), '/');
    }

    private function chatCompletionsUrl(): string
    {
        return "{$this->openaiBaseUrl()}/chat/completions";
    }

    private function audioTranscriptionsUrl(): string
    {
        return "{$this->openaiBaseUrl()}/audio/transcriptions";
    }
}
