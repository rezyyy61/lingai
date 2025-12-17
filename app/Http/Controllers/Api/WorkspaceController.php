<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $workspaces = Workspace::query()
            ->where('owner_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($workspaces);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'target_language' => ['nullable', 'string', 'max:10'],
            'support_language' => ['nullable', 'string', 'max:10'],
            'target_level' => ['nullable', 'string', 'in:A1,A2,B1,B2,C1,C2'],
        ]);

        $defaultLevel = config('learning_languages.default_level', 'A1');

        $level = $data['target_level'] ?? $defaultLevel;
        if (! in_array($level, ['A1','A2','B1','B2','C1','C2'], true)) {
            $level = $defaultLevel;
        }

        $supported = array_keys(config('learning_languages.supported', []));
        $defaultTarget = config('learning_languages.default_target', 'en');
        $defaultSupport = config('learning_languages.default_support', 'en');

        $target = $data['target_language'] ?? $defaultTarget;
        $support = $data['support_language'] ?? $defaultSupport;

        if (! in_array($target, $supported, true)) {
            $target = $defaultTarget;
        }

        if (! in_array($support, $supported, true)) {
            $support = $defaultSupport;
        }

        $slugBase = Str::slug($data['name']);
        $slug = $slugBase ?: 'workspace';

        $exists = Workspace::where('slug', $slug)->exists();
        if ($exists) {
            $slug .= '-' . Str::random(6);
        }

        $workspace = Workspace::create([
            'owner_id' => $user->id,
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'target_language' => $target,
            'support_language' => $support,
            'target_level' => $level,
        ]);

        $workspace->members()->syncWithoutDetaching([
            $user->id => ['role' => 'owner'],
        ]);

        return response()->json($workspace, 201);
    }

    public function show(Request $request, Workspace $workspace)
    {
        $user = $request->user();

        if ($workspace->owner_id !== $user->id) {
            abort(403);
        }

        return response()->json($workspace);
    }
}
