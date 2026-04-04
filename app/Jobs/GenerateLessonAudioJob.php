<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\Lessons\GenerateLessonAudio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateLessonAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public int $lessonId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("lesson-audio:{$this->lessonId}"))->dontRelease(),
        ];
    }

    public function handle(GenerateLessonAudio $generator): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson) {
            return;
        }

        if ((string) data_get($lesson->analysis_meta, 'audio_generation.status') !== 'processing') {
            return;
        }

        $generator->handle($lesson);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Lesson audio generation failed', [
            'lesson_id' => $this->lessonId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);

        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson) {
            return;
        }

        $meta = is_array($lesson->analysis_meta) ? $lesson->analysis_meta : [];
        data_set($meta, 'audio_generation.status', 'failed');
        data_set($meta, 'audio_generation.failed_at', now()->toIso8601String());

        $lesson->forceFill([
            'analysis_meta' => $meta,
        ])->save();
    }
}
