<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Models\LessonExercise;
use App\Models\LessonExerciseOption;
use App\Models\LessonGrammarPoint;
use App\Services\Lessons\LessonGrammarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateLessonGrammarJob implements ShouldQueue
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

    public function handle(LessonGrammarService $grammarService): void
    {
        $lesson = $this->lesson->fresh();

        if (! $lesson) {
            return;
        }

        if (! $lesson->original_text) {
            return;
        }

        try {
            $result = $grammarService->generateGrammar($lesson, $this->customPrompt);
        } catch (ConnectionException|RequestException|\Throwable $e) {
            report($e);
            return;
        }

        $grammarPoints = $result['grammar_points'] ?? [];
        $exercises = $result['exercises'] ?? [];

        if (empty($grammarPoints) && empty($exercises)) {
            return;
        }

        DB::transaction(function () use ($lesson, $grammarPoints, $exercises) {
            if ($this->replaceExisting) {
                LessonGrammarPoint::where('lesson_id', $lesson->id)->delete();
                LessonExercise::where('lesson_id', $lesson->id)
                    ->where('skill', 'grammar')
                    ->delete();
            }

            $grammarMap = [];

            foreach ($grammarPoints as $index => $point) {
                $rawId = $point['id'] ?? null;
                $rawTitle = $point['title'] ?? null;
                $rawLevel = $point['level'] ?? null;
                $rawDescription = $point['description'] ?? null;
                $rawPattern = $point['pattern'] ?? null;
                $rawExamples = $point['examples'] ?? [];
                $rawMeta = $point['meta'] ?? null;


                $key = ! is_array($rawId) ? $rawId : null;

                $title = ! is_array($rawTitle)
                    ? $rawTitle
                    : 'Grammar point ' . ($index + 1);

                $level = ! is_array($rawLevel)
                    ? $rawLevel
                    : null;

                $description = ! is_array($rawDescription)
                    ? $rawDescription
                    : null;

                if (is_array($rawPattern)) {
                    $pattern = $rawPattern['text'] ?? $rawPattern['pattern'] ?? null;
                    if (is_array($pattern)) {
                        $pattern = null;
                    }
                } else {
                    $pattern = $rawPattern;
                }

                $examples = is_array($rawExamples) ? $rawExamples : [];

                $meta = is_array($rawMeta) ? $rawMeta : null;

                $grammar = LessonGrammarPoint::create([
                    'lesson_id' => $lesson->id,
                    'key' => $key,
                    'title' => $title,
                    'level' => $level,
                    'description' => $description,
                    'pattern' => $pattern,
                    'examples' => json_encode($examples, JSON_UNESCAPED_UNICODE),
                    'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                ]);

                if (! empty($key)) {
                    $grammarMap[$key] = $grammar->id;
                }
            }


            $labels = ['A', 'B', 'C', 'D', 'E', 'F'];

            foreach ($exercises as $exerciseData) {
                $grammarKey = $exerciseData['grammar_id'] ?? null;
                $grammarId = $grammarKey && isset($grammarMap[$grammarKey])
                    ? $grammarMap[$grammarKey]
                    : null;

                $difficulty = $exerciseData['difficulty'] ?? 'easy';

                $exercise = LessonExercise::create([
                    'lesson_id' => $lesson->id,
                    'lesson_sentence_id' => null,
                    'type' => 'mcq',
                    'skill' => 'grammar',
                    'question_prompt' => $exerciseData['question_prompt'] ?? '',
                    'instructions' => $exerciseData['instructions'] ?? null,
                    'solution_explanation' => $exerciseData['solution_explanation'] ?? null,
                    'meta' => [
                        'grammar_key' => $grammarKey,
                        'grammar_point_id' => $grammarId,
                        'difficulty' => $difficulty,
                        'raw_meta' => $exerciseData['meta'] ?? null,
                    ],
                ]);

                if (! empty($exerciseData['options']) && is_array($exerciseData['options'])) {
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

