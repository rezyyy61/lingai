<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateLessonAudioScriptRequest;
use App\Jobs\GenerateLessonAudioScriptJob;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LessonAudioScriptController extends Controller
{
    public function store(GenerateLessonAudioScriptRequest $request, Lesson $lesson): JsonResponse
    {
        $response = DB::transaction(function () use ($lesson) {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedLesson->status === 'processing') {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'Audio script generation is already in progress.',
                ], 202);
            }

            $lockedLesson->forceFill([
                'status' => 'processing',
            ])->save();

            GenerateLessonAudioScriptJob::dispatch($lockedLesson->id)->afterCommit();

            return response()->json([
                'status' => 'processing',
                'message' => 'Audio script generation has been queued.',
            ], 202);
        });

        $lesson->refresh();

        return $response;
    }
}
