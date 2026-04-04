<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\Lessons\GenerateLessonAudioScript;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateLessonAudioScriptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public int $lessonId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("lesson-audio-script:{$this->lessonId}"))->dontRelease(),
        ];
    }

    public function handle(GenerateLessonAudioScript $generator): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson) {
            return;
        }

        if ((string) $lesson->status !== 'processing') {
            return;
        }

        $generator->handle($lesson);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Lesson audio script generation failed', [
            'lesson_id' => $this->lessonId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);

        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson) {
            return;
        }

        $lesson->forceFill([
            'status' => 'failed',
        ])->save();
    }
}
