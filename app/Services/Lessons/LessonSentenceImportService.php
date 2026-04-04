<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonSentence;
use Illuminate\Support\Facades\DB;

class LessonSentenceImportService
{
    public function import(Lesson $lesson, array $sentences, bool $replaceExisting = true): array
    {
        return DB::transaction(function () use ($lesson, $sentences, $replaceExisting) {
            if ($replaceExisting) {
                LessonSentence::query()->where('lesson_id', $lesson->id)->delete();
            }

            $existingTexts = LessonSentence::query()
                ->where('lesson_id', $lesson->id)
                ->orderBy('order_index')
                ->pluck('text')
                ->map(fn (string $text) => $this->normalizeTextKey($text))
                ->all();

            $nextOrderIndex = (int) LessonSentence::query()
                ->where('lesson_id', $lesson->id)
                ->max('order_index');

            $created = 0;
            $skipped = 0;
            $createdSentences = [];

            foreach ($sentences as $sentence) {
                $payload = $this->normalizePayload($sentence);
                $textKey = $this->normalizeTextKey((string) $payload['text']);

                if ($textKey === '') {
                    $skipped++;
                    continue;
                }

                if (in_array($textKey, $existingTexts, true)) {
                    $skipped++;
                    continue;
                }

                $nextOrderIndex++;

                $createdSentence = $lesson->sentences()->create($payload + [
                    'order_index' => $nextOrderIndex,
                ]);

                $createdSentences[] = $createdSentence;
                $existingTexts[] = $textKey;
                $created++;
            }

            return [
                'created' => $created,
                'skipped' => $skipped,
                'sentences' => $createdSentences,
            ];
        });
    }

    public function resequence(Lesson $lesson): void
    {
        $orderIndex = 1;

        foreach ($lesson->sentences()->orderBy('order_index')->get() as $sentence) {
            if ((int) $sentence->order_index !== $orderIndex) {
                $sentence->update(['order_index' => $orderIndex]);
            }

            $orderIndex++;
        }
    }

    protected function normalizePayload(array $sentence): array
    {
        return [
            'text' => trim((string) ($sentence['text'] ?? '')),
            'translation' => $this->nullableString($sentence['translation'] ?? null),
            'source' => $this->normalizeSource($sentence['source'] ?? null),
            'start_time' => $this->nullableInt($sentence['start_time'] ?? null),
            'end_time' => $this->nullableInt($sentence['end_time'] ?? null),
            'meta' => isset($sentence['meta']) && is_array($sentence['meta']) ? $sentence['meta'] : null,
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    protected function normalizeSource(mixed $source): string
    {
        $normalized = trim((string) ($source ?? ''));

        return in_array($normalized, ['original', 'generated'], true)
            ? $normalized
            : 'generated';
    }

    protected function normalizeTextKey(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
