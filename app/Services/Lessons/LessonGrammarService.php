<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LessonGrammarService
{
    public function generateGrammar(Lesson $lesson, ?string $customPrompt = null): array
    {
        if (! $lesson->original_text) {
            return [
                'grammar_points' => [],
                'exercises' => [],
            ];
        }

        $targetLanguage = $lesson->target_language ?? config('learning_languages.default_target', 'en');
        $supportLanguage = $lesson->support_language ?? config('learning_languages.default_support', 'en');

        $targetLabel = $this->labelForLanguage($targetLanguage);
        $supportLabel = $this->labelForLanguage($supportLanguage);

        $rawText = strip_tags($lesson->original_text);
        $plainText = trim((string) preg_replace('/\s+/', ' ', $rawText));

        $wordCount = str_word_count($plainText);

        $maxChars = (int) config('services.openai.grammar_max_chars', 5000);
        if (mb_strlen($plainText) > $maxChars) {
            $plainText = mb_substr($plainText, 0, $maxChars) . '...';
        }

        $customBlock = '';
        if ($customPrompt !== null && trim($customPrompt) !== '') {
            $customBlock = "\n\nAdditional user preferences and instructions for grammar. Follow them only if they do not conflict with the JSON schema or the rules:\n" . $customPrompt . "\n";
        }

        $userContent = <<<EOT
Extract ONLY the most important grammar points from this {$targetLabel} lesson in a compact, UI-friendly way.

Lesson language: {$targetLabel} ({$targetLanguage})
Learner language: {$supportLabel} ({$supportLanguage})
Approx length: {$wordCount} words

Rules:
- Return ONLY JSON. No extra text.
- Choose exactly 1 or 2 grammar points (prefer 1 if the text is short or simple).
- Keep each description short (max 3 short sentences).
- Avoid academic language. Speak like a friendly tutor in {$supportLabel}.
- Each grammar point must include:
  - WHEN we use it (real-life)
  - HOW we build it (structure)
  - ONE common mistake
- Provide exactly 2 examples:
  - One close to the lesson (source="lesson")
  - One simple extra example (source="extra")
- Examples must be in {$targetLabel}, translations in {$supportLabel}.
- No exercises at all.

Return JSON with this exact shape:

{
  "grammar_points": [
    {
      "id": "short_key",
      "title": "Short title in {$supportLabel}",
      "level": "A1|A2|B1|B2|C1|C2 or null",
      "description": "Short explanation in {$supportLabel}. Include when/how/mistake.",
      "pattern": "One short pattern line in {$targetLabel} notation.",
      "examples": [
        {
          "sentence": "Sentence in {$targetLabel}",
          "translation": "Natural {$supportLabel}",
          "source": "lesson|extra"
        }
      ],
      "meta": {
        "importance": "high|medium|low",
        "tags": ["tag_one", "tag_two"]
      }
    }
  ],
  "exercises": []
}

Lesson text:
{$plainText}
EOT;

        $base = rtrim((string) config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $endpoint = $base . '/chat/completions';

        $payload = [
            'model' => config('services.openai.chat_model', 'gpt-4.1-mini'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You extract compact grammar points for language learners and return strict JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => (int) config('services.openai.grammar_max_tokens', 900),
            'temperature' => 0.2,
        ];

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout((int) config('services.openai.grammar_timeout', 45))
                ->connectTimeout((int) config('services.openai.grammar_connect_timeout', 10))
                ->retry(1, 800)
                ->post($endpoint, $payload)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('LessonGrammarService: request failed', [
                'lesson_id' => $lesson->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);

            return [
                'grammar_points' => [],
                'exercises' => [],
            ];
        }

        $content = $response['choices'][0]['message']['content'] ?? '{}';
        $data = json_decode($content, true);

        if (! is_array($data)) {
            Log::warning('LessonGrammarService: invalid json', [
                'lesson_id' => $lesson->id ?? null,
                'content_head' => mb_substr((string) $content, 0, 900),
            ]);

            return [
                'grammar_points' => [],
                'exercises' => [],
            ];
        }

        $grammarPoints = $data['grammar_points'] ?? [];

        if (! is_array($grammarPoints)) {
            $grammarPoints = [];
        }

        $grammarPoints = $this->normalizeGrammarPoints($grammarPoints, $targetLabel, $supportLabel);

        return [
            'grammar_points' => $grammarPoints,
            'exercises' => [],
        ];
    }

    protected function normalizeGrammarPoints(array $points, string $targetLabel, string $supportLabel): array
    {
        $out = [];
        $seen = [];

        foreach ($points as $p) {
            if (! is_array($p)) {
                continue;
            }

            $id = trim((string) ($p['id'] ?? ''));
            $title = trim((string) ($p['title'] ?? ''));
            $description = trim((string) ($p['description'] ?? ''));
            $pattern = trim((string) ($p['pattern'] ?? ''));

            if ($id === '' || $title === '' || $description === '' || $pattern === '') {
                continue;
            }

            $key = mb_strtolower($id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $examples = $p['examples'] ?? [];
            if (! is_array($examples)) {
                $examples = [];
            }

            $examplesOut = [];
            foreach ($examples as $ex) {
                if (! is_array($ex)) {
                    continue;
                }

                $sentence = trim((string) ($ex['sentence'] ?? ''));
                $translation = trim((string) ($ex['translation'] ?? ''));
                $source = (string) ($ex['source'] ?? '');

                if ($sentence === '' || $translation === '') {
                    continue;
                }

                if ($source !== 'lesson' && $source !== 'extra') {
                    $source = 'extra';
                }

                $examplesOut[] = [
                    'sentence' => $sentence,
                    'translation' => $translation,
                    'source' => $source,
                ];
            }

            if (count($examplesOut) < 2) {
                continue;
            }

            $level = $p['level'] ?? null;
            if (! is_string($level) || trim($level) === '') {
                $level = null;
            }

            $meta = $p['meta'] ?? null;
            if (! is_array($meta)) {
                $meta = null;
            }

            $out[] = [
                'id' => $id,
                'title' => $title,
                'level' => $level,
                'description' => $description,
                'pattern' => $pattern,
                'examples' => array_slice($examplesOut, 0, 2),
                'meta' => $meta,
            ];
        }

        return array_slice($out, 0, 2);
    }

    protected function labelForLanguage(string $code): string
    {
        return match (strtolower($code)) {
            'fa', 'fas', 'per' => 'Persian (Farsi)',
            'nl', 'nld', 'dut' => 'Dutch',
            'en', 'eng' => 'English',
            'de', 'ger' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'tr' => 'Turkish',
            'ar' => 'Arabic',
            default => 'the target language',
        };
    }
}
