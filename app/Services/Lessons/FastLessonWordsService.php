<?php

namespace App\Services\Lessons;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FastLessonWordsService
{
    public function generate(string $text, string $targetLanguage = 'en', string $supportLanguage = 'en'): array
    {
        $text = $this->shrinkText($text, (int) config('services.openai.words_max_chars', 6000));

        $base = rtrim((string) config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $endpoint = $base . '/chat/completions';

        $payload = [
            'model' => (string) config('services.openai.fast_model', 'gpt-4.1-mini'),
            'temperature' => 0.2,
            'max_tokens' => (int) config('services.openai.words_max_tokens', 800),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Return only valid JSON. No extra text. Do not wrap JSON in markdown.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($text, $targetLanguage, $supportLanguage),
                ],
            ],
        ];

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->timeout((int) config('services.openai.words_timeout', 20))
                ->connectTimeout((int) config('services.openai.words_connect_timeout', 5))
                ->post($endpoint, $payload);
        } catch (Throwable $e) {
            Log::error('FastLessonWordsService: request exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('FastLessonWordsService: request failed', [
                'status' => $response->status(),
                'body_head' => mb_substr((string) $response->body(), 0, 1200),
            ]);

            return [];
        }

        $json = $response->json();
        $content = data_get($json, 'choices.0.message.content');

        $data = $this->decodeJsonObject($content);

        $words = $data['words'] ?? null;

        if (! is_array($words) || empty($words)) {
            Log::warning('FastLessonWordsService: empty words', [
                'finish_reason' => data_get($json, 'choices.0.finish_reason'),
                'usage' => data_get($json, 'usage'),
                'content_head' => mb_substr((string) $content, 0, 900),
                'decoded_keys' => is_array($data) ? array_keys($data) : null,
            ]);

            return [];
        }

        return $this->normalizeWords($words);
    }

    protected function buildPrompt(string $text, string $target, string $support): string
    {
        return <<<TXT
Extract vocabulary from this {$target} text.

Return JSON only, with exactly 10 items in "words" (if impossible, return at least 6).
Every term MUST appear in the text exactly as written (case-insensitive match is OK).
Avoid filler and platform boilerplate.

JSON:
{
  "words": [
    {
      "term": "word or phrase",
      "meaning": "short meaning in {$target}",
      "example_sentence": "short example in {$target}",
      "translation": "natural translation in {$support}"
    }
  ]
}

Text:
{$text}
TXT;
    }

    protected function shrinkText(string $text, int $maxChars): string
    {
        $t = trim((string) preg_replace('/\s+/', ' ', $text));

        if ($maxChars <= 0) {
            return $t;
        }

        if (mb_strlen($t) <= $maxChars) {
            return $t;
        }

        $half = (int) floor($maxChars / 2);
        $head = mb_substr($t, 0, $half);
        $tail = mb_substr($t, -$half);

        return $head . "\n...\n" . $tail;
    }

    protected function decodeJsonObject(?string $content): array
    {
        $raw = trim((string) $content);

        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    protected function normalizeWords(array $words): array
    {
        $out = [];

        foreach ($words as $w) {
            if (! is_array($w)) {
                continue;
            }

            $term = trim((string) ($w['term'] ?? $w['word'] ?? $w['text'] ?? ''));

            if ($term === '') {
                continue;
            }

            $out[] = [
                'term' => $term,
                'meaning' => $w['meaning'] ?? null,
                'example_sentence' => $w['example_sentence'] ?? null,
                'translation' => $w['translation'] ?? null,
            ];
        }

        return $out;
    }
}
