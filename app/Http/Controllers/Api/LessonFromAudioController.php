<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateLessonAnalysisJob;
use App\Models\Lesson;
use App\Models\Workspace;
use App\Services\OpenAI\OpenAiAudioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LessonFromAudioController extends Controller
{
    public function store(Request $request, Workspace $workspace, OpenAiAudioService $audioService)
    {
        $user = $request->user();

        if ($workspace->owner_id !== $user->id) {
            abort(403);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:mp3,mp4,mpeg,mpga,m4a,wav,webm'],
            'title' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:10'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $file = $data['file'];

        $storedPath = $file->store('lesson_sources');

        $language = $data['language'] ?? 'en';

        $transcription = $audioService->transcribeUploadedFile($file, $language);

        $text = $transcription['text'] ?? null;

        if (! $text) {
            Storage::delete($storedPath);

            return response()->json([
                'message' => 'Transcription failed',
            ], 422);
        }

        $lesson = Lesson::create([
            'user_id' => $request->user()?->id,
            'workspace_id' => $workspace->id,
            'title' => $data['title'] ?? 'Lesson from audio',
            'resource_type' => 'audio',
            'source_url' => null,
            'original_text' => $text,
            'language' => $workspace->target_language,
            'target_language' => $workspace->target_language,
            'support_language' => $workspace->support_language,
            'level' => $data['level'] ?? null,
            'short_description' => mb_substr($text, 0, 180),
            'tags' => $data['tags'] ?? [],
            'status' => 'draft',
        ]);

        GenerateLessonAnalysisJob::dispatch($lesson);

        return response()->json([
            'lesson' => $lesson,
            'transcription_preview' => mb_substr($text, 0, 400),
        ], 201);
    }
}
