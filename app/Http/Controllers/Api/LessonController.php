<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateLessonAnalysisJob;
use App\Models\Lesson;
use App\Models\Workspace;
use Illuminate\Http\Request;

class LessonController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Lesson::query()
            ->where('user_id', $user->id);

        if ($workspaceId = $request->integer('workspace_id')) {
            $query->where('workspace_id', $workspaceId);
        }

        if ($search = $request->string('q')->toString()) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        if ($level = $request->string('level')->toString()) {
            $query->where('level', $level);
        }

        if ($resourceType = $request->string('resource_type')->toString()) {
            $query->where('resource_type', $resourceType);
        }

        $lessons = $query
            ->latest()
            ->paginate(20);

        return response()->json($lessons);
    }

    public function show(Lesson $lesson)
    {
        $lesson->load(['words', 'sentences', 'exercises.options']);

        return response()->json($lesson);
    }

    public function store(Request $request, Workspace $workspace)
    {
        $user = $request->user();

        if ($workspace->owner_id !== $user->id) {
            abort(403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'original_text' => ['required', 'string'],
            'level' => ['nullable', 'string', 'max:10'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ]);

        $lesson = Lesson::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => $data['title'],
            'resource_type' => 'text',
            'source_url' => null,
            'original_text' => $data['original_text'],

            'language' => $workspace->target_language,

            'target_language' => $workspace->target_language,
            'support_language' => $workspace->support_language,

            'level' => $data['level'] ?? null,
            'short_description' => mb_substr($data['original_text'], 0, 180),
            'tags' => $data['tags'] ?? [],
            'status' => 'draft',
        ]);

        GenerateLessonAnalysisJob::dispatch($lesson);

        return response()->json($lesson, 201);
    }

    public function destroy(Lesson $lesson)
    {
        $lesson->delete();

        return response()->json([], 204);
    }
}
