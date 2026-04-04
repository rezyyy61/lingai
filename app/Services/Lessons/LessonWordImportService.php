<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonWord;
use Illuminate\Support\Facades\DB;

class LessonWordImportService
{
    public function import(Lesson $lesson, array $words, bool $replaceExisting = true): array
    {
        return DB::transaction(function () use ($lesson, $words, $replaceExisting) {
            if ($replaceExisting) {
                LessonWord::query()->where('lesson_id', $lesson->id)->delete();
            }

            $existingTerms = LessonWord::query()
                ->where('lesson_id', $lesson->id)
                ->pluck('term')
                ->map(fn (string $term) => $this->normalizeTermKey($term))
                ->all();

            $created = 0;
            $skipped = 0;
            $createdWords = [];

            foreach ($words as $word) {
                $payload = $this->normalizePayload($word);
                $termKey = $this->normalizeTermKey((string) $payload['term']);

                if ($termKey === '') {
                    $skipped++;
                    continue;
                }

                if (in_array($termKey, $existingTerms, true)) {
                    $skipped++;
                    continue;
                }

                $createdWord = $lesson->words()->create($payload);
                $createdWords[] = $createdWord;
                $existingTerms[] = $termKey;
                $created++;
            }

            return [
                'created' => $created,
                'skipped' => $skipped,
                'words' => $createdWords,
            ];
        });
    }

    protected function normalizePayload(array $word): array
    {
        return [
            'term' => trim((string) ($word['term'] ?? '')),
            'lemma' => $this->nullableString($word['lemma'] ?? null),
            'phonetic' => $this->nullableString($word['phonetic'] ?? null),
            'part_of_speech' => $this->nullableString($word['part_of_speech'] ?? null),
            'meaning' => $this->nullableString($word['meaning'] ?? null),
            'example_sentence' => $this->nullableString($word['example_sentence'] ?? null),
            'translation' => $this->nullableString($word['translation'] ?? null),
            'meta' => isset($word['meta']) && is_array($word['meta']) ? $word['meta'] : null,
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

    protected function normalizeTermKey(string $term): string
    {
        return mb_strtolower(trim($term));
    }
}
