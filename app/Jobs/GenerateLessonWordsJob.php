<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Models\LessonWord;
use App\Support\LessonContentGeneration;
use App\Services\Lessons\FastLessonWordsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateLessonWordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;

    public function __construct(
        public int $lessonId,
        public ?string $inlinePrompt = null,
        public bool $replaceExisting = true,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("lesson-flashcards:{$this->lessonId}"))->dontRelease(),
        ];
    }

    public function handle(FastLessonWordsService $service): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson || ! $lesson->original_text || trim((string) $lesson->original_text) === '') {
            Log::warning('GenerateLessonWordsJob: missing lesson or text', [
                'lesson_id' => $this->lessonId,
            ]);
            LessonContentGeneration::markFailed($this->lessonId, 'flashcards', 'Lesson text is missing.');
            return;
        }

        try {
            $words = $service->generate($lesson, $this->inlinePrompt);
        } catch (Throwable $e) {
            Log::error('GenerateLessonWordsJob: service exception', [
                'lesson_id' => $lesson->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
            LessonContentGeneration::markFailed($lesson->id, 'flashcards', 'Could not generate flashcards.');
            return;
        }

        if (! is_array($words) || empty($words)) {
            Log::warning('GenerateLessonWordsJob: empty words', [
                'lesson_id' => $lesson->id,
            ]);
            LessonContentGeneration::markFailed($lesson->id, 'flashcards', 'No flashcards were generated.');
            return;
        }

        try {
            $created = 0;

            DB::transaction(function () use ($lesson, $words, &$created) {
                if ($this->replaceExisting) {
                    LessonWord::where('lesson_id', $lesson->id)->delete();
                }

                $existingTerms = LessonWord::query()
                    ->where('lesson_id', $lesson->id)
                    ->pluck('term')
                    ->map(fn (string $term) => mb_strtolower(trim($term)))
                    ->all();

                foreach ($words as $idx => $word) {
                    if (! is_array($word)) {
                        Log::warning('GenerateLessonWordsJob: invalid word item', [
                            'lesson_id' => $lesson->id,
                            'index' => $idx,
                            'type' => gettype($word),
                        ]);
                        continue;
                    }

                    $term = trim((string) ($word['term'] ?? $word['word'] ?? $word['text'] ?? ''));

                    if ($term === '') {
                        Log::warning('GenerateLessonWordsJob: skipped word (empty term)', [
                            'lesson_id' => $lesson->id,
                            'index' => $idx,
                            'keys' => array_keys($word),
                        ]);
                        continue;
                    }

                    if (! $this->replaceExisting && in_array(mb_strtolower($term), $existingTerms, true)) {
                        continue;
                    }

                    LessonWord::create([
                        'lesson_id' => $lesson->id,
                        'term' => $term,
                        'meaning' => $word['meaning'] ?? null,
                        'example_sentence' => $word['example_sentence'] ?? null,
                        'translation' => $word['translation'] ?? null,
                    ]);

                    $created++;
                    $existingTerms[] = mb_strtolower($term);
                }

                if ($created === 0) {
                    Log::warning('GenerateLessonWordsJob: no rows created', [
                        'lesson_id' => $lesson->id,
                    ]);
                }
            });

            $itemCount = LessonWord::query()->where('lesson_id', $lesson->id)->count();

            if ($itemCount === 0) {
                LessonContentGeneration::markFailed($lesson->id, 'flashcards', 'No flashcards were saved.');
                return;
            }

            LessonContentGeneration::markReady($lesson->id, 'flashcards', [
                'message' => 'Flashcards are ready.',
                'item_count' => $itemCount,
            ]);
        } catch (Throwable $e) {
            Log::error('GenerateLessonWordsJob: db failure', [
                'lesson_id' => $lesson->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
            LessonContentGeneration::markFailed($lesson->id, 'flashcards', 'Could not save generated flashcards.');
            return;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('GenerateLessonWordsJob: failed', [
            'lesson_id' => $this->lessonId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);

        LessonContentGeneration::markFailed($this->lessonId, 'flashcards', 'Flashcard generation failed.');
    }
}
