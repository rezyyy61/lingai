<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LessonExerciseService
{
    public function generate(
        Lesson $lesson,
        array $words = [],
        array $grammarPoints = [],
        int $count = 18,
        ?string $customPrompt = null
    ): array {
        $text = (string) ($lesson->original_text ?? '');

        if (trim($text) === '') {
            return [];
        }

        $targetLanguage = (string) ($lesson->target_language ?? config('learning_languages.default_target', 'en'));
        $supportLanguage = (string) ($lesson->support_language ?? config('learning_languages.default_support', 'en'));

        $plainText = $this->normalizeText($text);
        $plainText = $this->shrinkText($plainText, (int) config('services.openai.exercises_max_chars', 7000));

        $count = max(10, min(24, (int) $count));
        $minCount = max(8, $count - 2);

        $vocabMin = max(4, (int) floor($count * 0.4));
        $grammarMin = max(4, (int) floor($count * 0.4));

        if ($vocabMin + $grammarMin > $count) {
            $vocabMin = (int) floor($count / 2);
            $grammarMin = $count - $vocabMin;
        }

        $base = rtrim((string) config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $endpoint = $base . '/chat/completions';

        $wordsCompact = $this->compactWords($words);
        $grammarCompact = $this->compactGrammar($grammarPoints);

        $payload = [
            'model' => (string) config('services.openai.chat_model', 'gpt-4.1-mini'),
            'temperature' => 0.2,
            'max_tokens' => (int) config('services.openai.exercises_max_tokens', 2200),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Return only valid JSON. No extra text. Do not wrap JSON in markdown.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt(
                        $plainText,
                        $targetLanguage,
                        $supportLanguage,
                        $wordsCompact,
                        $grammarCompact,
                        $count,
                        $minCount,
                        $vocabMin,
                        $grammarMin,
                        $customPrompt
                    ),
                ],
            ],
        ];

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->timeout((int) config('services.openai.exercises_timeout', 60))
                ->connectTimeout((int) config('services.openai.exercises_connect_timeout', 10))
                ->retry(1, 900)
                ->post($endpoint, $payload);
        } catch (Throwable $e) {
            Log::error('LessonExerciseService: request exception', [
                'lesson_id' => $lesson->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
            return [];
        }

        if (! $response->successful()) {
            Log::warning('LessonExerciseService: request failed', [
                'lesson_id' => $lesson->id ?? null,
                'status' => $response->status(),
                'body_head' => mb_substr((string) $response->body(), 0, 1200),
            ]);
            return [];
        }

        $json = $response->json();
        $content = data_get($json, 'choices.0.message.content');

        $data = $this->decodeJsonObject($content);
        $exercises = $data['exercises'] ?? null;

        if (! is_array($exercises) || empty($exercises)) {
            Log::warning('LessonExerciseService: empty exercises', [
                'lesson_id' => $lesson->id ?? null,
                'finish_reason' => data_get($json, 'choices.0.finish_reason'),
                'usage' => data_get($json, 'usage'),
                'content_head' => mb_substr((string) $content, 0, 900),
                'decoded_keys' => is_array($data) ? array_keys($data) : null,
            ]);
            return [];
        }

        $normalized = $this->normalizeExercises($exercises);

        if (count($normalized) < $minCount) {
            Log::warning('LessonExerciseService: not enough normalized exercises', [
                'lesson_id' => $lesson->id ?? null,
                'expected_min' => $minCount,
                'got' => count($normalized),
            ]);
        }

        return array_slice($normalized, 0, $count);
    }

    protected function buildPrompt(
        string $text,
        string $targetLanguage,
        string $supportLanguage,
        array $words,
        array $grammarPoints,
        int $count,
        int $minCount,
        int $vocabMin,
        int $grammarMin,
        ?string $customPrompt
    ): string {
        $targetLabel = $this->labelForLanguage($targetLanguage);
        $supportLabel = $this->labelForLanguage($supportLanguage);

        $customBlock = '';
        if ($customPrompt !== null && trim($customPrompt) !== '') {
            $customBlock = "\n\nAdditional user instructions (follow only if they do not conflict with JSON schema and MCQ rules):\n" . $customPrompt . "\n";
        }

        $wordsBlock = json_encode($words, JSON_UNESCAPED_UNICODE);
        $grammarBlock = json_encode($grammarPoints, JSON_UNESCAPED_UNICODE);

        return <<<TXT
You will create MULTIPLE-CHOICE exercises (MCQ only) for a {$targetLabel} lesson.

Lesson language: {$targetLabel} ({$targetLanguage})
Learner language: {$supportLabel} ({$supportLanguage})

You are given:
1) A vocabulary list extracted from the lesson (may be empty).
2) A grammar points list extracted from the lesson (may be empty).
3) The lesson text.

Goal:
- Create a compact set of MCQ exercises that cover BOTH vocabulary AND grammar.
- Prefer using the provided vocabulary/grammar lists. If they are empty, derive from the text.
- Keep everything fast and practical.

Hard rules:
- Output ONLY JSON. No extra text.
- All exercises MUST be "type": "mcq".
- Create exactly {$count} exercises (if impossible, return at least {$minCount}).
- Each exercise MUST have:
  - "skill": "vocabulary" or "grammar" or "comprehension"
  - "difficulty": "easy" or "medium"
  - "question_prompt": in {$targetLabel}
  - "instructions": in {$supportLabel} (short)
  - "solution_explanation": in {$supportLabel} (short, clear)
  - "options": 3 or 4 items
- Exactly ONE option must have "is_correct": true.
- "is_correct" MUST be a JSON boolean (true/false), never a string.
- Option texts MUST be in {$targetLabel}. Option explanations MUST be in {$supportLabel}.
- Keep "solution_explanation" under ~120 characters.
- Keep each option "explanation" under ~80 characters.
- Prefer 3 options (use 4 only if needed).
- Avoid trick questions. Keep it learner-friendly.
- Avoid greetings, subscribe/like, channel talk, and filler content.

Balance rules:
- At least {$vocabMin} exercises MUST be vocabulary-focused.
- At least {$grammarMin} exercises MUST be grammar-focused.
- Remaining can be comprehension or additional vocab/grammar.

Output JSON schema (exact):
{
  "exercises": [
    {
      "type": "mcq",
      "skill": "vocabulary|grammar|comprehension",
      "difficulty": "easy|medium",
      "question_prompt": "Question in {$targetLabel}",
      "instructions": "Short instructions in {$supportLabel}",
      "solution_explanation": "Short explanation in {$supportLabel}",
      "options": [
        {
          "text": "Option in {$targetLabel}",
          "is_correct": true,
          "explanation": "Short reason in {$supportLabel}"
        }
      ]
    }
  ]
}

Vocabulary list (from lesson):
{$wordsBlock}

Grammar points list (from lesson):
{$grammarBlock}
{$customBlock}

Lesson text:
{$text}
TXT;
    }

    protected function normalizeText(string $text): string
    {
        $raw = strip_tags($text);
        return trim((string) preg_replace('/\s+/', ' ', $raw));
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

    protected function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $v = trim(mb_strtolower($value));
            if ($v === 'true' || $v === '1' || $v === 'yes') return true;
            if ($v === 'false' || $v === '0' || $v === 'no' || $v === '') return false;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed === true;
    }

    protected function normalizeExercises(array $exercises): array
    {
        $out = [];

        foreach ($exercises as $ex) {
            if (! is_array($ex)) {
                continue;
            }

            $type = (string) ($ex['type'] ?? '');
            if ($type !== 'mcq') {
                continue;
            }

            $skill = (string) ($ex['skill'] ?? '');
            $skill = match ($skill) {
                'vocab' => 'vocabulary',
                'grammer' => 'grammar',
                default => $skill,
            };

            if (! in_array($skill, ['vocabulary', 'grammar', 'comprehension'], true)) {
                continue;
            }

            $difficulty = (string) ($ex['difficulty'] ?? 'easy');
            if (! in_array($difficulty, ['easy', 'medium'], true)) {
                $difficulty = 'easy';
            }

            $question = trim((string) ($ex['question_prompt'] ?? ''));
            if ($question === '') {
                continue;
            }

            $instructions = $ex['instructions'] ?? null;
            $instructions = is_string($instructions) && trim($instructions) !== '' ? trim($instructions) : null;

            $solution = $ex['solution_explanation'] ?? null;
            $solution = is_string($solution) && trim($solution) !== '' ? trim($solution) : null;

            $options = $ex['options'] ?? null;
            if (! is_array($options) || count($options) < 3) {
                continue;
            }

            $optionsOut = [];
            $correctCount = 0;

            foreach (array_values($options) as $opt) {
                if (! is_array($opt)) {
                    continue;
                }

                $text = trim((string) ($opt['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $isCorrectRaw = $opt['is_correct'] ?? ($opt['isCorrect'] ?? ($opt['correct'] ?? false));
                $isCorrect = $this->toBool($isCorrectRaw);

                if ($isCorrect) {
                    $correctCount++;
                }

                $explanation = $opt['explanation'] ?? null;
                $explanation = is_string($explanation) && trim($explanation) !== '' ? trim($explanation) : null;

                $optionsOut[] = [
                    'text' => $text,
                    'is_correct' => $isCorrect,
                    'explanation' => $explanation,
                ];
            }

            if (count($optionsOut) < 3) {
                continue;
            }

            if ($correctCount !== 1) {
                $fixed = false;

                if (isset($ex['correct_option_index']) && is_numeric($ex['correct_option_index'])) {
                    $idx = (int) $ex['correct_option_index'];
                    if ($idx >= 0 && $idx < count($optionsOut)) {
                        foreach ($optionsOut as $i => $o) {
                            $optionsOut[$i]['is_correct'] = ($i === $idx);
                        }
                        $fixed = true;
                    }
                }

                if (! $fixed) {
                    $picked = null;
                    foreach ($optionsOut as $i => $o) {
                        if ($o['is_correct'] === true) {
                            $picked = $i;
                            break;
                        }
                    }

                    if ($picked === null) {
                        $picked = 0;
                    }

                    foreach ($optionsOut as $i => $o) {
                        $optionsOut[$i]['is_correct'] = ($i === $picked);
                    }
                }
            }

            $out[] = [
                'type' => 'mcq',
                'skill' => $skill,
                'difficulty' => $difficulty,
                'question_prompt' => $question,
                'instructions' => $instructions,
                'solution_explanation' => $solution,
                'options' => array_slice($optionsOut, 0, 4),
                'meta' => is_array($ex['meta'] ?? null) ? $ex['meta'] : null,
            ];
        }

        return array_values($out);
    }

    protected function compactWords(array $words): array
    {
        $out = [];
        $seen = [];

        foreach ($words as $w) {
            if (! is_array($w)) {
                continue;
            }

            $term = trim((string) ($w['term'] ?? ''));
            if ($term === '') {
                continue;
            }

            $k = mb_strtolower($term);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;

            $meaning = $w['meaning'] ?? null;
            $meaning = is_string($meaning) && trim($meaning) !== '' ? trim($meaning) : null;

            $example = $w['example_sentence'] ?? null;
            $example = is_string($example) && trim($example) !== '' ? trim($example) : null;

            $out[] = [
                'term' => $term,
                'meaning' => $meaning,
                'example_sentence' => $example,
            ];

            if (count($out) >= 18) {
                break;
            }
        }

        return $out;
    }

    protected function compactGrammar(array $points): array
    {
        $out = [];
        $seen = [];

        foreach ($points as $p) {
            if (! is_array($p)) {
                continue;
            }

            $id = trim((string) ($p['id'] ?? $p['key'] ?? ''));
            $title = trim((string) ($p['title'] ?? ''));
            $pattern = trim((string) ($p['pattern'] ?? ''));

            if ($id === '' && $title === '') {
                continue;
            }

            $k = mb_strtolower($id !== '' ? $id : $title);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;

            $out[] = [
                'id' => $id !== '' ? $id : null,
                'title' => $title !== '' ? $title : null,
                'pattern' => $pattern !== '' ? $pattern : null,
            ];

            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
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
