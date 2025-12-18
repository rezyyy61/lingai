<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAiLessonJob;
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

        $meta = (array) ($lesson->analysis_meta ?? []);

        return response()->json(array_merge($lesson->toArray(), [
            'lesson_pack' => data_get($meta, 'lesson_pack'),
        ]));
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
            'resource_type' => \App\Enums\LessonResourceType::Text,
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

    public function generateAnalysis(Request $request, Lesson $lesson)
    {
        $user = $request->user();
        if ($lesson->user_id !== $user->id) {
            abort(403);
        }

        GenerateLessonAnalysisJob::dispatch($lesson);

        return response()->json([
            'status' => 'queued',
        ], 202);
    }

    public function generate(Request $request, Workspace $workspace)
    {
        $user = $request->user();

        if ($workspace->owner_id !== $user->id) {
            abort(403);
        }

        $supportedLevels = config('learning_languages.supported_levels', ['A1','A2','B1','B2','C1','C2']);

        $data = $request->validate([
            'topic' => ['required', 'string', 'max:160'],
            'goal' => ['nullable', 'string', 'max:80'],
            'level' => ['nullable', 'string', 'in:' . implode(',', $supportedLevels)],
            'length' => ['nullable', 'string', 'in:short,medium,long'],
            'keywords' => ['nullable', 'array', 'max:12'],
            'keywords.*' => ['string', 'max:40'],
            'title_hint' => ['nullable', 'string', 'max:120'],
        ]);

        $level = $data['level'] ?? ($workspace->target_level ?? config('learning_languages.default_level', 'A2'));
        if (!in_array($level, $supportedLevels, true)) {
            $level = config('learning_languages.default_level', 'A2');
        }

        $length = $data['length'] ?? 'medium';
        $titleHint = trim((string) ($data['title_hint'] ?? ''));
        $goal = trim((string) ($data['goal'] ?? ''));

        $tags = array_values(array_unique(array_filter(array_merge(
            ['ai', 'dialogue'],
            $data['keywords'] ?? []
        ))));

        $analysisMeta = [
            'ai_generation' => [
                'topic' => $data['topic'],
                'goal' => $data['goal'] ?? null,
                'length' => $length,
                'keywords' => $data['keywords'] ?? [],
                'title_hint' => $data['title_hint'] ?? null,
                'dialogue_only' => true,
            ],
        ];

        $lesson = Lesson::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => $titleHint !== '' ? $titleHint : 'Generating dialogue…',
            'resource_type' => \App\Enums\LessonResourceType::TextAi,
            'source_url' => null,
            'original_text' => '',
            'language' => $workspace->target_language,
            'target_language' => $workspace->target_language,
            'support_language' => $workspace->support_language,
            'level' => $level,
            'short_description' => null,
            'tags' => $tags,
            'status' => 'generating',
            'analysis_meta' => $analysisMeta,
        ]);

        GenerateAiLessonJob::dispatch($lesson->id);

        return response()->json($lesson, 202);
    }

}
