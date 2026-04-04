<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\AI\AzureOpenAiLessonClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateLessonAudioScript
{
    public function __construct(
        protected AzureOpenAiLessonClient $client,
    ) {}

    public function handle(Lesson $lesson): Lesson
    {
        if (! $lesson->hasProcessableOriginalText()) {
            throw new RuntimeException('Lesson original text is required to generate an audio script.');
        }

        $response = $this->client->generateLessonScript(
            lessonText: (string) $lesson->original_text,
            preferredOutputLanguage: null,
            context: [
                'lesson_id' => $lesson->id,
                'existing_title' => $lesson->title,
                'existing_level' => $lesson->level,
            ],
        );

        $mapped = $this->mapResponseToLessonAttributes($lesson, $response);

        DB::transaction(function () use ($lesson, $mapped): void {
            /** @var Lesson $freshLesson */
            $freshLesson = Lesson::query()
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $freshLesson->forceFill($mapped)->save();
        });

        return $lesson->fresh();
    }

    public function mapResponseToLessonAttributes(Lesson $lesson, array $response): array
    {
        $summary = $this->stringValue($response, 'summary', true);
        $sourceLanguage = $this->stringValue($response, 'source_language', true);
        $outputLanguage = $this->stringValue($response, 'output_language', true);
        $title = $this->stringValue($response, 'title', true);
        $level = $this->normalizeLevel($this->stringValue($response, 'level', true));
        $spokenSegments = $this->normalizeSpokenSegments($response['spoken_segments'] ?? null);

        $vocabulary = $this->normalizeVocabularyItems($response['key_vocabulary'] ?? null);
        $expressions = $this->normalizeExpressionItems($response['key_expressions'] ?? null);
        $questions = $this->normalizeQuestionItems($response['comprehension_questions'] ?? null);

        $normalizedSourceLanguage = $this->normalizeLanguageCode($sourceLanguage);
        $normalizedOutputLanguage = $this->normalizeLanguageCode($outputLanguage);

        $existingMeta = is_array($lesson->analysis_meta) ? $lesson->analysis_meta : [];

        $audioScriptMeta = array_merge($response, [
            'source_language' => $sourceLanguage,
            'source_language_code' => $normalizedSourceLanguage,
            'output_language' => $outputLanguage,
            'output_language_code' => $normalizedOutputLanguage,
            'title' => $title,
            'level' => $level ?? 'unknown',
            'summary' => $summary,
            'key_vocabulary' => $vocabulary,
            'key_expressions' => $expressions,
            'comprehension_questions' => $questions,
            'spoken_segments' => $spokenSegments,
            'generated_at' => now()->toIso8601String(),
        ]);

        $updatedMeta = array_merge($existingMeta, [
            'audio_script' => $audioScriptMeta,
        ]);

        if (! isset($updatedMeta['language_direction']) && $normalizedSourceLanguage !== null) {
            $direction = data_get(config('learning_languages.supported'), "{$normalizedSourceLanguage}.direction");

            if (is_string($direction) && $direction !== '') {
                $updatedMeta['language_direction'] = $direction;
            }
        }

        $attributes = [
            'analysis_overview' => $summary,
            'analysis_vocabulary' => $this->formatVocabularySummary($vocabulary),
            'analysis_study_tips' => $this->formatStudyTips($expressions, $questions),
            'analysis_meta' => $updatedMeta,
            'status' => 'ready',
        ];

        if ($this->shouldReplaceTitle((string) $lesson->title) && $title !== '') {
            $attributes['title'] = Str::limit($title, 255, '');
        }

        if ($level !== null) {
            $attributes['level'] = $level;
        }

        if ($normalizedSourceLanguage !== null) {
            $attributes['language'] = $normalizedSourceLanguage;
        }

        return $attributes;
    }

    protected function normalizeSpokenSegments(mixed $items): array
    {
        if (! is_array($items) || $items === []) {
            throw new RuntimeException('Lesson script response is missing a valid spoken_segments array.');
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = trim((string) ($item['type'] ?? ''));
            $speaker = trim((string) ($item['speaker'] ?? ''));
            $style = trim((string) ($item['style'] ?? ''));
            $text = trim((string) ($item['text'] ?? ''));
            $pause = $item['pause_ms'] ?? null;

            if ($type === '' || $speaker === '' || $style === '' || $text === '' || ! is_int($pause) && ! ctype_digit((string) $pause)) {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'speaker' => $speaker,
                'style' => $style,
                'pause_ms' => max(0, (int) $pause),
                'text' => $text,
            ];
        }

        if ($normalized === []) {
            throw new RuntimeException('Lesson script response is missing a valid spoken_segments array.');
        }

        return $normalized;
    }

    protected function stringValue(array $payload, string $key, bool $required = false): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            if ($required) {
                throw new RuntimeException("Lesson script response is missing a valid {$key} field.");
            }

            return '';
        }

        $value = trim($value);

        if ($required && $value === '') {
            throw new RuntimeException("Lesson script response is missing a valid {$key} field.");
        }

        return $value;
    }

    protected function normalizeVocabularyItems(mixed $items): array
    {
        if (! is_array($items)) {
            throw new RuntimeException('Lesson script response is missing a valid key_vocabulary array.');
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $term = trim((string) ($item['term'] ?? ''));
            $meaning = trim((string) ($item['meaning'] ?? ''));
            $example = trim((string) ($item['example'] ?? ''));

            if ($term === '' || $meaning === '' || $example === '') {
                continue;
            }

            $normalized[] = [
                'term' => $term,
                'meaning' => $meaning,
                'example' => $example,
            ];
        }

        return $normalized;
    }

    protected function normalizeExpressionItems(mixed $items): array
    {
        if (! is_array($items)) {
            throw new RuntimeException('Lesson script response is missing a valid key_expressions array.');
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $expression = trim((string) ($item['expression'] ?? ''));
            $meaning = trim((string) ($item['meaning'] ?? ''));
            $example = trim((string) ($item['example'] ?? ''));

            if ($expression === '' || $meaning === '' || $example === '') {
                continue;
            }

            $normalized[] = [
                'expression' => $expression,
                'meaning' => $meaning,
                'example' => $example,
            ];
        }

        return $normalized;
    }

    protected function normalizeQuestionItems(mixed $items): array
    {
        if (! is_array($items)) {
            throw new RuntimeException('Lesson script response is missing a valid comprehension_questions array.');
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            $normalized[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $normalized;
    }

    protected function normalizeLevel(string $level): ?string
    {
        $level = strtoupper(trim($level));
        $supported = config('learning_languages.supported_levels', ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']);

        return in_array($level, $supported, true) ? $level : null;
    }

    protected function normalizeLanguageCode(string $language): ?string
    {
        $language = Str::lower(trim($language));

        if ($language === '') {
            return null;
        }

        $supported = (array) config('learning_languages.supported', []);

        if (array_key_exists($language, $supported)) {
            return $language;
        }

        foreach ($supported as $code => $meta) {
            $label = Str::lower((string) ($meta['label'] ?? ''));
            $native = Str::lower((string) ($meta['native'] ?? ''));

            if ($language === $label || $language === $native) {
                return (string) $code;
            }
        }

        if (preg_match('/^[a-z]{2,5}$/', $language) === 1) {
            return Str::limit($language, 10, '');
        }

        return null;
    }

    protected function shouldReplaceTitle(string $title): bool
    {
        $normalized = Str::of($title)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->lower()
            ->value();

        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, [
            'lesson',
            'new lesson',
            'untitled',
            'untitled lesson',
            'generated dialogue',
            'generating dialogue...',
            'generating dialogue…',
            'generating lesson...',
            'generating lesson…',
        ], true);
    }

    protected function formatVocabularySummary(array $vocabulary): string
    {
        if ($vocabulary === []) {
            return 'No key vocabulary was extracted.';
        }

        $lines = [];

        foreach ($vocabulary as $item) {
            $lines[] = sprintf(
                '%s: %s Example: %s',
                $item['term'],
                $item['meaning'],
                $item['example']
            );
        }

        return implode("\n", $lines);
    }

    protected function formatStudyTips(array $expressions, array $questions): string
    {
        $sections = [];

        if ($expressions !== []) {
            $lines = array_map(
                fn (array $item): string => sprintf(
                    '%s: %s Example: %s',
                    $item['expression'],
                    $item['meaning'],
                    $item['example']
                ),
                $expressions
            );

            $sections[] = 'Key expressions:' . "\n" . implode("\n", $lines);
        }

        if ($questions !== []) {
            $lines = array_map(
                fn (array $item): string => sprintf(
                    'Q: %s A: %s',
                    $item['question'],
                    $item['answer']
                ),
                $questions
            );

            $sections[] = 'Comprehension check:' . "\n" . implode("\n", $lines);
        }

        $sections[] = 'Study guidance:' . "\n"
            . 'Read the spoken script aloud once, repeat the key expressions from memory, and answer the comprehension questions without looking back at the text.';

        return implode("\n\n", $sections);
    }
}
