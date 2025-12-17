<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\Lessons\LessonReadAloudService;
use Illuminate\Http\Request;

class LessonReadAloudController extends Controller
{
    public function show(Request $request, Lesson $lesson, LessonReadAloudService $svc)
    {
        $user = $request->user();
        if ((int) $lesson->user_id !== (int) $user->id) {
            abort(403);
        }

        $data = $request->validate([
            'speed' => ['nullable', 'string', 'in:slow,normal,fast'],
            'format' => ['nullable', 'string', 'in:mp3,wav'],
        ]);

        return response()->json(
            $svc->getExisting(
                lesson: $lesson,
                speed: (string) ($data['speed'] ?? 'normal'),
                format: (string) ($data['format'] ?? 'mp3'),
            )
        );
    }

    public function generate(Request $request, Lesson $lesson, LessonReadAloudService $svc)
    {
        $user = $request->user();
        if ((int) $lesson->user_id !== (int) $user->id) {
            abort(403);
        }

        $data = $request->validate([
            'speed' => ['nullable', 'string', 'in:slow,normal,fast'],
            'format' => ['nullable', 'string', 'in:mp3,wav'],
            'mode' => ['nullable', 'string', 'in:auto,narration,dialogue,quote'],
            'voice_pair' => ['nullable', 'string', 'in:auto,female_male,female_female,male_male'],
        ]);

        return response()->json(
            $svc->generate(
                lesson: $lesson,
                speed: (string) ($data['speed'] ?? 'normal'),
                format: (string) ($data['format'] ?? 'mp3'),
                mode: (string) ($data['mode'] ?? 'auto'),
                voicePair: (string) ($data['voice_pair'] ?? 'auto'),
            )
        );
    }
}
