<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Models\LessonSentence;
use App\Support\LessonContentGeneration;
use App\Services\Lessons\LessonSentenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateLessonSentencesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public $tries = 1;

    public $backoff = 30;

    public function __construct(
        public int $lessonId,
        public ?string $customPrompt = null,
        public bool $replaceExisting = true
    )
    {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("lesson-shadowing:{$this->lessonId}"))->dontRelease(),
        ];
    }

    public function handle(LessonSentenceService $service): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson || ! $lesson->original_text || trim((string) $lesson->original_text) === '') {
            Log::warning('GenerateLessonSentencesJob: missing lesson or text', [
                'lesson_id' => $this->lessonId,
            ]);
            LessonContentGeneration::markFailed($this->lessonId, 'shadowing', 'Lesson text is missing.');
            return;
        }

        $target = $lesson->target_language ?? config('learning_languages.default_target', 'en');
        $support = $lesson->support_language ?? config('learning_languages.default_support', 'en');

        try {
            $items = $service->generate((string) $lesson->original_text, $target, $support);
        } catch (Throwable $e) {
            Log::error('GenerateLessonSentencesJob: service exception', [
                'lesson_id' => $lesson->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
            LessonContentGeneration::markFailed($lesson->id, 'shadowing', 'Could not generate sentences.');
            return;
        }

        if (! is_array($items) || empty($items)) {
            Log::warning('GenerateLessonSentencesJob: empty sentences', [
                'lesson_id' => $lesson->id,
            ]);
            LessonContentGeneration::markFailed($lesson->id, 'shadowing', 'No sentences were generated.');
            return;
        }

        try {
            DB::transaction(function () use ($lesson, $items) {
                if ($this->replaceExisting) {
                    LessonSentence::where('lesson_id', $lesson->id)->delete();
                }

                $created = 0;

                foreach ($items as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $text = trim((string) ($item['text'] ?? ''));
                    $translation = $item['translation'] ?? null;

                    if ($text === '') {
                        continue;
                    }

                    LessonSentence::create([
                        'lesson_id' => $lesson->id,
                        'order_index' => $index + 1,
                        'text' => $text,
                        'translation' => (is_string($translation) && trim($translation) !== '') ? trim($translation) : null,
                        'source' => 'original',
                        'meta' => null,
                    ]);

                    $created++;
                }

                if ($created === 0) {
                    Log::warning('GenerateLessonSentencesJob: no rows created', [
                        'lesson_id' => $lesson->id,
                    ]);
                }
            });

            $itemCount = LessonSentence::query()->where('lesson_id', $lesson->id)->count();

            if ($itemCount === 0) {
                LessonContentGeneration::markFailed($lesson->id, 'shadowing', 'No sentences were saved.');
                return;
            }

            LessonContentGeneration::markReady($lesson->id, 'shadowing', [
                'message' => 'Shadowing sentences are ready.',
                'item_count' => $itemCount,
            ]);
        } catch (Throwable $e) {
            Log::error('GenerateLessonSentencesJob: db failure', [
                'lesson_id' => $lesson->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
            LessonContentGeneration::markFailed($lesson->id, 'shadowing', 'Could not save generated sentences.');
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('GenerateLessonSentencesJob: failed', [
            'lesson_id' => $this->lessonId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);

        LessonContentGeneration::markFailed($this->lessonId, 'shadowing', 'Sentence generation failed.');
    }
}
