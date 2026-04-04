<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateLessonAudioRequest;
use App\Jobs\GenerateLessonAudioJob;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LessonAudioController extends Controller
{
    public function store(GenerateLessonAudioRequest $request, Lesson $lesson): JsonResponse
    {
        return DB::transaction(function () use ($lesson) {
            /** @var Lesson $lockedLesson */
            $lockedLesson = Lesson::query()
                ->whereKey($lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            $meta = is_array($lockedLesson->analysis_meta) ? $lockedLesson->analysis_meta : [];
            $audioGeneration = is_array(data_get($meta, 'audio_generation'))
                ? data_get($meta, 'audio_generation')
                : [];

            if ((string) ($audioGeneration['status'] ?? '') === 'processing') {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'Lesson audio generation is already in progress.',
                ], 202);
            }

            data_set($meta, 'audio_generation.status', 'processing');
            data_set($meta, 'audio_generation.format', config('lesson_generation.audio.format', 'mp3'));
            data_set($meta, 'audio_generation.voice', $audioGeneration['voice'] ?? null);
            data_set($meta, 'audio_generation.started_at', now()->toIso8601String());

            $lockedLesson->forceFill([
                'analysis_meta' => $meta,
            ])->save();

            GenerateLessonAudioJob::dispatch($lockedLesson->id)->afterCommit();

            return response()->json([
                'status' => 'processing',
                'message' => 'Lesson audio generation has been queued.',
            ], 202);
        });
    }
}
