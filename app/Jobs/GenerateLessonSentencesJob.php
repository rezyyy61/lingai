<?php

namespace App\Jobs;

use App\Enums\LessonNlpTask;
use App\Models\Lesson;
use App\Models\LessonSentence;
use App\Services\Lessons\LessonNlpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateLessonSentencesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Lesson $lesson;

    public ?string $customPrompt;

    public bool $replaceExisting;

    public $tries = 2;

    public $backoff = 60;

    public function __construct(Lesson $lesson, ?string $customPrompt = null, bool $replaceExisting = true)
    {
        $this->lesson = $lesson;
        $this->customPrompt = $customPrompt;
        $this->replaceExisting = $replaceExisting;
    }

    public function handle(LessonNlpService $nlp): void
    {
        $lesson = $this->lesson->fresh();

        if (! $lesson) {
            return;
        }

        if (! $lesson->original_text) {
            return;
        }

        try {
            $analysis = $nlp->analyzeText(
                $lesson->original_text,
                $lesson->target_language ?? config('learning_languages.default_target', 'en'),
                $lesson->support_language ?? config('learning_languages.default_support', 'en'),
                LessonNlpTask::SentencesOnly,
                $this->customPrompt
            );
        } catch (ConnectionException|RequestException|\Throwable $e) {
            report($e);
            return;
        }

        $sentences = $analysis['sentences'] ?? [];

        if (! is_array($sentences) || empty($sentences)) {
            return;
        }

        DB::transaction(function () use ($lesson, $sentences) {
            if ($this->replaceExisting) {
                LessonSentence::where('lesson_id', $lesson->id)->delete();
            }

            foreach ($sentences as $index => $sentenceData) {
                LessonSentence::create([
                    'lesson_id' => $lesson->id,
                    'order_index' => $index + 1,
                    'text' => $sentenceData['text'] ?? '',
                    'translation' => $sentenceData['translation'] ?? null,
                    'source' => 'original',
                    'meta' => $sentenceData['meta'] ?? null,
                ]);
            }
        });
    }

    public function failed(): void
    {
    }
}
