<?php

namespace App\Jobs;

use App\Enums\LessonNlpTask;
use App\Models\Lesson;
use App\Models\LessonExercise;
use App\Models\LessonExerciseOption;
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

class ProcessLessonTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Lesson $lesson;

    public $tries = 2;

    public $backoff = 60;

    public function __construct(Lesson $lesson)
    {
        $this->lesson = $lesson;
    }

    public function handle(LessonNlpService $nlp): void
    {
        $lesson = $this->lesson->fresh();

        if (! $lesson) {
            return;
        }

        $lesson->update(['status' => 'processing']);

        try {
            $analysis = $nlp->analyzeText(
                $lesson->original_text,
                $lesson->target_language ?? config('learning_languages.default_target', 'en'),
                $lesson->support_language ?? config('learning_languages.default_support', 'en'),
                LessonNlpTask::FullLesson,
                null
            );
        } catch (ConnectionException|RequestException|\Throwable $e) {
            report($e);
            $lesson->update(['status' => 'failed']);
            return;
        }

        DB::transaction(function () use ($lesson, $analysis) {
            $sentences = $analysis['sentences'] ?? [];
            $exercises = $analysis['exercises'] ?? [];

            foreach ($sentences as $index => $sentenceData) {
                LessonSentence::create([
                    'lesson_id' => $lesson->id,
                    'order_index' => $index + 1,
                    'text' => $sentenceData['text'] ?? '',
                    'source' => 'original',
                ]);
            }

            foreach ($exercises as $exerciseData) {
                $exercise = LessonExercise::create([
                    'lesson_id' => $lesson->id,
                    'lesson_sentence_id' => null,
                    'type' => 'mcq',
                    'skill' => $exerciseData['skill'] ?? 'vocabulary',
                    'question_prompt' => $exerciseData['question_prompt'] ?? '',
                    'instructions' => $exerciseData['instructions'] ?? null,
                    'solution_explanation' => $exerciseData['solution_explanation'] ?? null,
                    'meta' => $exerciseData['meta'] ?? null,
                ]);

                if (! empty($exerciseData['options']) && is_array($exerciseData['options'])) {
                    $labels = ['A', 'B', 'C', 'D', 'E', 'F'];

                    $options = array_values($exerciseData['options']);

                    foreach ($options as $index => $optionData) {
                        LessonExerciseOption::create([
                            'lesson_exercise_id' => $exercise->id,
                            'order_index' => $index + 1,
                            'label' => $optionData['label'] ?? ($labels[$index] ?? null),
                            'text' => $optionData['text'] ?? '',
                            'is_correct' => (bool) ($optionData['is_correct'] ?? false),
                            'explanation' => $optionData['explanation'] ?? null,
                        ]);
                    }
                }
            }

            $lesson->update(['status' => 'ready']);
        });
    }

    public function failed(): void
    {
        $this->lesson->update(['status' => 'failed']);
    }
}
