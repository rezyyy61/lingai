<?php

namespace App\Services\AI;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AzureOpenAiLessonClient
{
    protected const SPOKEN_SEGMENT_SCHEMA = <<<'TXT'
  "spoken_segments": [
    {
      "type": "string",
      "speaker": "string",
      "style": "string",
      "pause_ms": 700,
      "text": "string"
    }
  ]
TXT;

    public function generateLessonScript(string $lessonText, ?string $preferredOutputLanguage = null, array $context = []): array
    {
        $endpoint = $this->endpoint();
        $payload = $this->payload($lessonText, $preferredOutputLanguage, $context);

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->post($endpoint, $payload);
        } catch (Throwable $exception) {
            Log::error('Azure lesson script request failed', [
                'endpoint' => $endpoint,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'lesson_id' => $context['lesson_id'] ?? null,
            ]);

            throw new RuntimeException('Lesson script generation request failed.', previous: $exception);
        }

        if (! $response->successful()) {
            Log::warning('Azure lesson script request returned an error response', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'lesson_id' => $context['lesson_id'] ?? null,
                'response_excerpt' => mb_substr($response->body(), 0, 1000),
            ]);

            throw new RuntimeException('Lesson script generation failed with an upstream error.');
        }

        $content = $this->extractContent($response);
        $decoded = $this->decodeJsonObject($content);

        if (! is_array($decoded) || $decoded === []) {
            Log::warning('Azure lesson script response did not contain valid JSON', [
                'lesson_id' => $context['lesson_id'] ?? null,
                'status' => $response->status(),
                'content_excerpt' => mb_substr($content, 0, 1000),
            ]);

            throw new RuntimeException('Lesson script generation returned malformed JSON.');
        }

        return $decoded;
    }

    protected function endpoint(): string
    {
        $endpoint = rtrim((string) config('lesson_generation.azure_openai.endpoint'), '/');

        if ($endpoint === '') {
            throw new RuntimeException('Lesson generation endpoint is not configured.');
        }

        $deployment = rawurlencode((string) config('lesson_generation.azure_openai.deployment'));
        $apiVersion = urlencode((string) config('lesson_generation.azure_openai.api_version'));
        $useV1 = (bool) config('lesson_generation.azure_openai.use_v1', true);

        if ($useV1) {
            return "{$endpoint}/openai/v1/chat/completions";
        }

        return "{$endpoint}/openai/deployments/{$deployment}/chat/completions?api-version={$apiVersion}";
    }

    protected function headers(): array
    {
        $apiKey = (string) config('lesson_generation.azure_openai.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('Lesson generation API key is not configured.');
        }

        return [
            'api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function payload(string $lessonText, ?string $preferredOutputLanguage, array $context): array
    {
        $deployment = (string) config('lesson_generation.azure_openai.deployment');

        if ($deployment === '') {
            throw new RuntimeException('Lesson generation deployment is not configured.');
        }

        $payload = [
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $this->userPrompt($lessonText, $preferredOutputLanguage, $context),
                ],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => (float) config('lesson_generation.azure_openai.temperature', 0.3),
            'max_completion_tokens' => (int) config('lesson_generation.azure_openai.max_completion_tokens', 1800),
        ];

        if ((bool) config('lesson_generation.azure_openai.use_v1', true)) {
            $payload['model'] = $deployment;
        }

        return $payload;
    }

    protected function timeout(): int
    {
        return (int) config('lesson_generation.azure_openai.timeout', 90);
    }

    protected function connectTimeout(): int
    {
        return (int) config('lesson_generation.azure_openai.connect_timeout', 10);
    }

    protected function systemPrompt(): string
    {
        return <<<'TXT'
You generate structured multilingual teaching scripts for language lessons.
Return ONLY valid JSON with the exact requested schema. Do not include markdown or extra keys.
Detect the source language from the lesson text.
Use the same language for output unless a preferred output language is explicitly provided.
Create a teacher-style spoken lesson for future text-to-speech use.
Do not simply read, summarize line by line, or paraphrase the entire source text.
Base the lesson on the source text's topic, meaning, and useful language patterns.
The spoken_segments array is the canonical lesson-audio script output.
TXT;
    }

    protected function userPrompt(string $lessonText, ?string $preferredOutputLanguage, array $context): string
    {
        $preferredOutputLanguage = trim((string) $preferredOutputLanguage);
        $existingTitle = trim((string) ($context['existing_title'] ?? ''));
        $existingLevel = trim((string) ($context['existing_level'] ?? ''));

        $preferredOutputLine = $preferredOutputLanguage !== ''
            ? "Preferred output language override: {$preferredOutputLanguage}"
            : 'Preferred output language override: none. Use the detected source language.';

        $existingTitleLine = $existingTitle !== ''
            ? "Existing lesson title hint: {$existingTitle}"
            : 'Existing lesson title hint: none';

        $existingLevelLine = $existingLevel !== ''
            ? "Existing lesson level hint: {$existingLevel}"
            : 'Existing lesson level hint: unknown';

        $prompt = <<<TXT
Generate a spoken teaching lesson from the text below.

{$preferredOutputLine}
{$existingTitleLine}
{$existingLevelLine}

Return ONLY valid JSON with this exact schema:
{
  "source_language": "string",
  "output_language": "string",
  "title": "string",
  "level": "A1|A2|B1|B2|C1|C2|unknown",
  "summary": "string",
  "key_vocabulary": [
    {
      "term": "string",
      "meaning": "string",
      "example": "string"
    }
  ],
  "key_expressions": [
    {
      "expression": "string",
      "meaning": "string",
      "example": "string"
    }
  ],
  "comprehension_questions": [
    {
      "question": "string",
      "answer": "string"
    }
  ],
{spoken_segments_schema}
}

Rules:
- Detect the source language from the text.
- Keep the output language equal to the source language unless an explicit override is given.
- spoken_segments must be a non-empty array.
- Each spoken segment must include type, speaker, style, pause_ms, and text.
- Each text value must sound natural when spoken by a teacher.
- The spoken segments should teach the learner, not just repeat the source text.
- Use "coach" or "helper" for the speaker field.
- pause_ms must be a non-negative integer.
- Keep the summary concise and useful.
- Include only meaningful vocabulary, expressions, and comprehension questions.

Lesson text:
{$lessonText}
TXT;

        return str_replace('{spoken_segments_schema}', trim(self::SPOKEN_SEGMENT_SCHEMA), $prompt);
    }

    protected function extractContent(Response $response): string
    {
        $content = data_get($response->json(), 'choices.0.message.content');

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                    continue;
                }

                $text = is_array($item) ? ($item['text'] ?? $item['content'] ?? null) : null;

                if (is_string($text)) {
                    $parts[] = $text;
                }
            }

            return trim(implode('', $parts));
        }

        return '';
    }

    protected function decodeJsonObject(string $content): array
    {
        $content = trim($content);

        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');

        if ($start === false) {
            return [];
        }

        $candidate = substr($content, $start);
        $end = strrpos($candidate, '}');

        if ($end === false) {
            return [];
        }

        $decoded = json_decode(substr($candidate, 0, $end + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
