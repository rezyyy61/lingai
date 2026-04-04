<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateLessonReadAloudRequest;
use App\Jobs\GenerateLessonReadAloudJob;
use App\Models\Lesson;
use App\Services\Lessons\LessonReadAloudState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LessonReadAloudController extends Controller
{
    public function show(Request $request, Lesson $lesson, LessonReadAloudState $state)
    {
        $user = $request->user();
        if ((int) $lesson->user_id !== (int) $user->id) {
            abort(403);
        }

        return response()->json($state->get($lesson));
    }

    public function generate(GenerateLessonReadAloudRequest $request, Lesson $lesson)
    {
        return DB::transaction(function () use ($lesson) {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $meta = is_array($lockedLesson->analysis_meta) ? $lockedLesson->analysis_meta : [];

            if ((string) data_get($meta, 'read_aloud.status', 'pending') === 'processing') {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'Read-aloud generation is already in progress.',
                ], 202);
            }

            data_set($meta, 'read_aloud.status', 'processing');
            data_set($meta, 'read_aloud.started_at', now()->toIso8601String());
            data_set($meta, 'read_aloud.format', config('lesson_generation.read_aloud.format', 'mp3'));

            $lockedLesson->forceFill([
                'analysis_meta' => $meta,
            ])->save();

            GenerateLessonReadAloudJob::dispatch($lockedLesson->id)->afterCommit();

            return response()->json([
                'status' => 'processing',
                'message' => 'Read-aloud generation has been queued.',
            ], 202);
        });
    }
}
