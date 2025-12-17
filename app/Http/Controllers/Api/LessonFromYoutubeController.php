<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateLessonAnalysisJob;
use App\Models\Lesson;
use App\Models\Workspace;
use App\Services\Youtube\YoutubeTranscriptService;
use Illuminate\Http\Request;

class LessonFromYoutubeController extends Controller
{
    public function store(Request $request, Workspace $workspace, YoutubeTranscriptService $yt)
    {
        $user = $request->user();

        if ($workspace->owner_id !== $user->id) {
            abort(403);
        }

        $data = $request->validate([
            'youtube_url' => ['required', 'url'],
            'title' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:10'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $language = $data['language'] ?? 'en';

        $text = $yt->getTranscriptTextFromUrl($data['youtube_url'], $language);

        if (! $text) {
            return response()->json([
                'message' => 'No transcript found for this video or transcripts are disabled.',
            ], 422);
        }

        $lesson = Lesson::create([
            'user_id' => $request->user()?->id,
            'workspace_id' => $workspace->id,
            'title' => $data['title'] ?? 'Lesson from YouTube',
            'resource_type' => \App\Enums\LessonResourceType::Video,
            'source_url' => $data['youtube_url'],
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
            'transcript_preview' => mb_substr($text, 0, 400),
        ], 201);
    }
}
