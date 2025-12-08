<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateLessonExercisesJob;
use App\Models\Lesson;
use App\Models\LessonExercise;
use App\Models\LessonExerciseAttempt;
use Illuminate\Http\Request;

class LessonExerciseController extends Controller
{
    public function index(Request $request, Lesson $lesson)
    {
        $query = $lesson->exercises()->with('options');

        if ($skill = $request->string('skill')->toString()) {
            $query->where('skill', $skill);
        }

        if ($type = $request->string('type')->toString()) {
            $query->where('type', $type);
        }

        $exercises = $query->orderBy('id')->get();

        return response()->json($exercises);
    }

    public function show(Lesson $lesson, LessonExercise $exercise)
    {
        if ($exercise->lesson_id !== $lesson->id) {
            abort(404);
        }

        $exercise->load('options');

        return response()->json($exercise);
    }

    public function attempt(Request $request, LessonExercise $exercise)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Authentication required.',
            ], 401);
        }

        $exercise->load('options');

        $data = $request->validate([
            'selected_option_id' => ['required', 'integer'],
        ]);

        $selectedOption = $exercise->options->firstWhere('id', $data['selected_option_id']);

        if (! $selectedOption) {
            return response()->json([
                'message' => 'Invalid option for this exercise.',
            ], 422);
        }

        $isCorrect = (bool) $selectedOption->is_correct;
        $correctOption = $exercise->options->firstWhere('is_correct', true);

        $feedback = null;

        if (! $isCorrect) {
            $feedback = $selectedOption->explanation
                ?: $exercise->solution_explanation;
        }

        $attempt = LessonExerciseAttempt::create([
            'lesson_exercise_id' => $exercise->id,
            'user_id' => $user->id,
            'user_answer' => $selectedOption->text,
            'is_correct' => $isCorrect,
            'score' => $isCorrect ? 1 : 0,
            'feedback' => $feedback,
            'meta' => [
                'selected_option_id' => $selectedOption->id,
                'correct_option_id' => $correctOption?->id,
            ],
        ]);

        return response()->json([
            'is_correct' => $isCorrect,
            'exercise_id' => $exercise->id,
            'type' => $exercise->type,
            'skill' => $exercise->skill,
            'selected_option' => [
                'id' => $selectedOption->id,
                'text' => $selectedOption->text,
                'explanation' => $selectedOption->explanation,
            ],
            'correct_option' => $correctOption ? [
                'id' => $correctOption->id,
                'text' => $correctOption->text,
                'explanation' => $correctOption->explanation,
            ] : null,
            'solution_explanation' => $exercise->solution_explanation,
            'feedback' => $feedback,
            'attempt_id' => $attempt->id,
        ]);

    }

    public function generate(Request $request, Lesson $lesson)
    {
        if (! $lesson->original_text) {
            return response()->json([
                'message' => 'Lesson has no original_text. Cannot generate exercises.',
            ], 422);
        }

        $data = $request->validate([
            'custom_prompt' => ['nullable', 'string', 'max:2000'],
            'replace_existing' => ['sometimes', 'boolean'],
        ]);

        $customPrompt = $data['custom_prompt'] ?? null;
        $replaceExisting = $data['replace_existing'] ?? true;

        GenerateLessonExercisesJob::dispatch($lesson, $customPrompt, $replaceExisting);

        return response()->json([
            'status' => 'queued',
            'message' => 'Exercise generation job has been queued for this lesson.',
        ], 202);
    }

}
