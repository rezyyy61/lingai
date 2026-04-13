<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateLessonGrammarJob;
use App\Models\Lesson;
use App\Models\LessonExercise;
use App\Models\LessonGrammarPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\LessonContentGeneration;

class LessonGrammarController extends Controller
{
    public function index(Request $request, Lesson $lesson)
    {
        $grammarPoints = $lesson->grammarPoints()
            ->orderBy('id')
            ->get();

        if (! $request->boolean('with_exercises')) {
            return response()->json($grammarPoints);
        }

        $exercises = $lesson->exercises()
            ->with('options')
            ->where('skill', 'grammar')
            ->orderBy('id')
            ->get();

        $byGrammarId = [];

        foreach ($exercises as $exercise) {
            $grammarPointId = $exercise->meta['grammar_point_id'] ?? null;

            if (! $grammarPointId) {
                continue;
            }

            $byGrammarId[$grammarPointId][] = $exercise;
        }

        $data = $grammarPoints->map(function (LessonGrammarPoint $point) use ($byGrammarId) {
            return [
                'id' => $point->id,
                'lesson_id' => $point->lesson_id,
                'key' => $point->key,
                'title' => $point->title,
                'level' => $point->level,
                'description' => $point->description,
                'pattern' => $point->pattern,
                'examples' => $point->examples,
                'meta' => $point->meta,
                'exercises' => $byGrammarId[$point->id] ?? [],
            ];
        });

        return response()->json($data);
    }

    public function show(Request $request, Lesson $lesson, LessonGrammarPoint $grammarPoint)
    {
        if ($grammarPoint->lesson_id !== $lesson->id) {
            abort(404);
        }

        $includeExercises = $request->boolean('with_exercises', true);

        if (! $includeExercises) {
            return response()->json($grammarPoint);
        }

        $exercises = LessonExercise::query()
            ->with('options')
            ->where('lesson_id', $lesson->id)
            ->where('skill', 'grammar')
            ->where('meta->grammar_point_id', $grammarPoint->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'grammar_point' => $grammarPoint,
            'exercises' => $exercises,
        ]);
    }

    public function generate(Request $request, Lesson $lesson)
    {
        $user = $request->user();

        if ($user && $lesson->user_id !== $user->id) {
            abort(403);
        }

        $data = $request->validate([
            'prompt' => ['nullable', 'string'],
            'replace_existing' => ['sometimes', 'boolean'],
        ]);

        $prompt = $data['prompt'] ?? null;
        $replace = $data['replace_existing'] ?? true;

        return DB::transaction(function () use ($lesson, $prompt, $replace) {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (LessonContentGeneration::currentStatus($lockedLesson, 'grammar') === 'processing') {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'Grammar generation is already in progress.',
                ], 202);
            }

            LessonContentGeneration::markProcessing(
                $lockedLesson->id,
                'grammar',
                'Generating grammar notes for this lesson.'
            );

            GenerateLessonGrammarJob::dispatch($lockedLesson->id, $prompt, $replace)->afterCommit();

            return response()->json([
                'status' => 'processing',
                'message' => 'Grammar generation has been queued.',
            ], 202);
        });
    }
}
