<?php

namespace App\Jobs;

use App\Enums\LessonNlpTask;
use App\Models\Lesson;
use App\Models\LessonWord;
use App\Services\Lessons\LessonNlpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateLessonWordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Lesson $lesson;

    public ?string $customWordPrompt;

    public bool $replaceExisting;

    public $tries = 2;

    public $backoff = 60;

    public function __construct(Lesson $lesson, ?string $customWordPrompt = null, bool $replaceExisting = true)
    {
        $this->lesson = $lesson;
        $this->customWordPrompt = $customWordPrompt;
        $this->replaceExisting = $replaceExisting;
    }

    public function handle(LessonNlpService $nlp): void
    {
        $lesson = $this->lesson->fresh();

        if (! $lesson) {
            return;
        }

        try {
            $analysis = $nlp->analyzeText(
                $lesson->original_text,
                $lesson->target_language ?? config('learning_languages.default_target', 'en'),
                $lesson->support_language ?? config('learning_languages.default_support', 'en'),
                LessonNlpTask::WordsOnly,
                $this->customWordPrompt
            );
        } catch (ConnectionException|RequestException|\Throwable $e) {
            report($e);
            return;
        }

        $words = $analysis['words'] ?? [];

        if (! is_array($words) || empty($words)) {
            return;
        }

        DB::transaction(function () use ($lesson, $words) {
            if ($this->replaceExisting) {
                LessonWord::where('lesson_id', $lesson->id)->delete();
            }

            foreach ($words as $wordData) {
                LessonWord::create([
                    'lesson_id' => $lesson->id,
                    'term' => $wordData['term'] ?? '',
                    'lemma' => $wordData['lemma'] ?? null,
                    'phonetic' => $wordData['phonetic'] ?? null,
                    'part_of_speech' => $wordData['part_of_speech'] ?? null,
                    'meaning' => $wordData['meaning'] ?? null,
                    'example_sentence' => $wordData['example_sentence'] ?? null,
                    'translation' => $wordData['translation'] ?? null,
                    'meta' => $wordData['meta'] ?? null,
                ]);
            }
        });
    }

    public function failed(): void
    {
    }
}
