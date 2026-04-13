<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Models\LessonExercise;
use App\Models\LessonExerciseOption;
use App\Models\LessonGrammarPoint;
use App\Models\LessonWord;
use App\Support\LessonContentGeneration;
use App\Services\Lessons\LessonExerciseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateLessonExercisesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public int $tries = 1;

    public int $backoff = 30;

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
            (new WithoutOverlapping("lesson-exercises:{$this->lessonId}"))->dontRelease(),
        ];
    }

    public function handle(LessonExerciseService $service): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson || ! $lesson->original_text || trim((string) $lesson->original_text) === '') {
            LessonContentGeneration::markFailed($this->lessonId, 'exercises', 'Lesson text is missing.');
            return;
        }

        $words = LessonWord::query()
            ->where('lesson_id', $lesson->id)
            ->orderBy('id')
            ->get(['term', 'meaning', 'example_sentence', 'translation'])
            ->map(fn ($w) => [
                'term' => $w->term,
                'meaning' => $w->meaning,
                'example_sentence' => $w->example_sentence,
                'translation' => $w->translation,
            ])
            ->all();

        $grammarPoints = LessonGrammarPoint::query()
            ->where('lesson_id', $lesson->id)
            ->orderBy('id')
            ->get(['key', 'title', 'pattern'])
            ->map(fn ($g) => [
                'id' => $g->key,
                'title' => $g->title,
                'pattern' => $g->pattern,
            ])
            ->all();

        try {
            $exercises = $service->generate(
                $lesson,
                $words,
                $grammarPoints,
                (int) config('services.openai.exercises_count', 18),
                $this->customPrompt
            );
        } catch (Throwable $e) {
            Log::error('GenerateLessonExercisesJob: service exception', [
                'lesson_id' => $lesson->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
            LessonContentGeneration::markFailed($lesson->id, 'exercises', 'Could not generate exercises.');
            return;
        }

        if (! is_array($exercises) || empty($exercises)) {
            LessonContentGeneration::markFailed($lesson->id, 'exercises', 'No exercises were generated.');
            return;
        }

        try {
            DB::transaction(function () use ($lesson, $exercises) {
                if ($this->replaceExisting) {
                    LessonExercise::where('lesson_id', $lesson->id)->delete();
                }

                $labels = ['A', 'B', 'C', 'D', 'E', 'F'];

                foreach ($exercises as $exerciseData) {
                    if (! is_array($exerciseData)) {
                        continue;
                    }

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

                    $options = $exerciseData['options'] ?? null;

                    if (! is_array($options) || empty($options)) {
                        continue;
                    }

                    foreach (array_values($options) as $index => $optionData) {
                        if (! is_array($optionData)) {
                            continue;
                        }

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
            });

            $itemCount = LessonExercise::query()->where('lesson_id', $lesson->id)->count();

            if ($itemCount === 0) {
                LessonContentGeneration::markFailed($lesson->id, 'exercises', 'No exercises were saved.');
                return;
            }

            LessonContentGeneration::markReady($lesson->id, 'exercises', [
                'message' => 'Exercises are ready.',
                'item_count' => $itemCount,
            ]);
        } catch (Throwable $e) {
            Log::error('GenerateLessonExercisesJob: db failure', [
                'lesson_id' => $lesson->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            report($e);
            LessonContentGeneration::markFailed($lesson->id, 'exercises', 'Could not save generated exercises.');
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('GenerateLessonExercisesJob: failed', [
            'lesson_id' => $this->lessonId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);

        LessonContentGeneration::markFailed($this->lessonId, 'exercises', 'Exercise generation failed.');
    }
}
