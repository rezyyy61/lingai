<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Models\LessonSentence;
use App\Services\Lessons\LessonSentenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateLessonSentencesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Lesson $lesson;

    public ?string $customPrompt;

    public bool $replaceExisting;

    public int $timeout = 90;

    public $tries = 1;

    public $backoff = 30;

    public function __construct(Lesson $lesson, ?string $customPrompt = null, bool $replaceExisting = true)
    {
        $this->lesson = $lesson;
        $this->customPrompt = $customPrompt;
        $this->replaceExisting = $replaceExisting;
    }

    public function handle(LessonSentenceService $service): void
    {
        $lesson = $this->lesson->fresh();

        if (! $lesson || ! $lesson->original_text || trim((string) $lesson->original_text) === '') {
            Log::warning('GenerateLessonSentencesJob: missing lesson or text', [
                'lesson_id' => $this->lesson->id ?? null,
            ]);
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
            return;
        }

        if (! is_array($items) || empty($items)) {
            Log::warning('GenerateLessonSentencesJob: empty sentences', [
                'lesson_id' => $lesson->id,
            ]);
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
        } catch (Throwable $e) {
            Log::error('GenerateLessonSentencesJob: db failure', [
                'lesson_id' => $lesson->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('GenerateLessonSentencesJob: failed', [
            'lesson_id' => $this->lesson->id ?? null,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
