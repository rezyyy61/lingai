<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\Lessons\GenerateLessonReadAloud;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateLessonReadAloudJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public int $lessonId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("lesson-read-aloud:{$this->lessonId}"))->dontRelease(),
        ];
    }

    public function handle(GenerateLessonReadAloud $generator): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson) {
            return;
        }

        if ((string) data_get($lesson->analysis_meta, 'read_aloud.status', 'pending') !== 'processing') {
            return;
        }

        try {
            $generator->handle($lesson);
        } catch (Throwable $exception) {
            Log::error('Lesson read-aloud generation failed', [
                'lesson_id' => $this->lessonId,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            $this->markLessonFailed();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Lesson read-aloud generation failed', [
            'lesson_id' => $this->lessonId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);

        $this->markLessonFailed();
    }

    protected function markLessonFailed(): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson) {
            return;
        }

        $meta = is_array($lesson->analysis_meta) ? $lesson->analysis_meta : [];
        data_set($meta, 'read_aloud.status', 'failed');
        data_set($meta, 'read_aloud.failed_at', now()->toIso8601String());

        $lesson->forceFill([
            'analysis_meta' => $meta,
        ])->save();
    }
}
