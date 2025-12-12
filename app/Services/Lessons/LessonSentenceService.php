<?php

namespace App\Services\Lessons;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LessonSentenceService
{
    public function generate(string $text, string $targetLanguage = 'en', string $supportLanguage = 'en'): array
    {
        $text = $this->shrinkText($text, (int) config('services.openai.shadowing_max_chars', 7000));

        $base = rtrim((string) config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $endpoint = $base . '/chat/completions';

        $payload = [
            'model' => (string) config('services.openai.fast_model', 'gpt-4.1-mini'),
            'temperature' => 0.2,
            'max_tokens' => (int) config('services.openai.shadowing_max_tokens', 1200),
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
                ->timeout((int) config('services.openai.shadowing_timeout', 25))
                ->connectTimeout((int) config('services.openai.shadowing_connect_timeout', 6))
                ->post($endpoint, $payload);
        } catch (Throwable $e) {
            Log::error('FastLessonShadowingService: request exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
            return [];
        }

        if (! $response->successful()) {
            Log::warning('FastLessonShadowingService: request failed', [
                'status' => $response->status(),
                'body_head' => mb_substr((string) $response->body(), 0, 1200),
            ]);
            return [];
        }

        $json = $response->json();
        $content = data_get($json, 'choices.0.message.content');

        $data = $this->decodeJsonObject($content);
        $items = $data['sentences'] ?? null;

        if (! is_array($items) || empty($items)) {
            Log::warning('FastLessonShadowingService: empty sentences', [
                'finish_reason' => data_get($json, 'choices.0.finish_reason'),
                'usage' => data_get($json, 'usage'),
                'content_head' => mb_substr((string) $content, 0, 900),
                'decoded_keys' => is_array($data) ? array_keys($data) : null,
            ]);
            return [];
        }

        return $this->normalizeSentences($items);
    }

    protected function buildPrompt(string $text, string $target, string $support): string
    {
        return <<<TXT
Create shadowing-ready sentences from this {$target} lesson text and translate them into {$support}.

Shadowing rules:
- Pick ONLY content-rich, natural sentences that a learner can repeat aloud.
- Exclude intros/outros, greetings, calls to action, channel/video talk, filler, timestamps, and platform words.
- Avoid fragments and messy broken lines.
- Prefer 5–16 words per sentence. Do not exceed 22 words.
- If needed, you may lightly rewrite or split long sentences into shorter, natural ones while keeping the meaning.
- Return 12–20 sentences.

Output JSON only, exactly in this format:
{
  "sentences": [
    { "text": "…", "translation": "…" }
  ]
}

Requirements:
- "text" must be in {$target}.
- "translation" must be natural {$support} (no transliteration).
- No extra keys. No extra text outside JSON.

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

    protected function normalizeSentences(array $items): array
    {
        $out = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));
            $translation = $item['translation'] ?? null;

            if ($text === '') {
                continue;
            }

            $key = mb_strtolower(preg_replace('/\s+/', ' ', $text) ?? $text);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $out[] = [
                'text' => $text,
                'translation' => (is_string($translation) && trim($translation) !== '') ? trim($translation) : null,
            ];
        }

        return $out;
    }
}
