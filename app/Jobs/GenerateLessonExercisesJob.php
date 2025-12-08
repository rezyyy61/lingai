<?php

namespace App\Jobs;

use App\Enums\LessonNlpTask;
use App\Models\Lesson;
use App\Models\LessonExercise;
use App\Models\LessonExerciseOption;
use App\Services\Lessons\LessonNlpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateLessonExercisesJob implements ShouldQueue
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
                LessonNlpTask::ExercisesOnly,
                $this->customPrompt
            );
        } catch (ConnectionException|RequestException|\Throwable $e) {
            report($e);
            return;
        }

        $exercises = $analysis['exercises'] ?? [];

        if (! is_array($exercises) || empty($exercises)) {
            return;
        }

        DB::transaction(function () use ($lesson, $exercises) {
            if ($this->replaceExisting) {
                LessonExercise::where('lesson_id', $lesson->id)->delete();
            }

            foreach ($exercises as $exerciseData) {
                $exercise = LessonExercise::create([
                    'lesson_id' => $lesson->id,
                    'lesson_sentence_id' => $exerciseData['lesson_sentence_id'] ?? null,
                    'type' => $exerciseData['type'] ?? 'mcq',
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
        });
    }

    public function failed(): void
    {
    }
}
