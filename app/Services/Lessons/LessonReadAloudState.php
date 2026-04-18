<?php

namespace App\Services\Lessons;

use App\Models\Lesson;

class LessonReadAloudState
{
    public function get(Lesson $lesson): array
    {
        $meta = is_array($lesson->analysis_meta) ? $lesson->analysis_meta : [];
        $readAloud = is_array(data_get($meta, 'read_aloud')) ? data_get($meta, 'read_aloud') : [];
        $status = (string) ($readAloud['status'] ?? 'pending');
        $audioUrl = trim((string) ($readAloud['audio_url'] ?? ''));

        return [
            'status' => in_array($status, ['pending', 'processing', 'ready', 'failed'], true) ? $status : 'pending',
            'exists' => $audioUrl !== '',
            'audio_url' => $audioUrl !== '' ? $audioUrl : null,
            'generated_at' => $readAloud['generated_at'] ?? null,
            'voice' => $readAloud['voice'] ?? null,
            'locale' => $readAloud['locale'] ?? null,
            'rate' => $readAloud['rate'] ?? null,
            'format' => $readAloud['format'] ?? null,
            'chunk_count' => isset($readAloud['chunk_count']) ? (int) $readAloud['chunk_count'] : null,
            'generation_version' => $readAloud['generation_version'] ?? null,
            'current_generation_version' => $this->currentGenerationVersion(),
            'is_stale' => $this->isStale($readAloud),
            'config_snapshot' => is_array($readAloud['config_snapshot'] ?? null) ? $readAloud['config_snapshot'] : null,
        ];
    }

    protected function currentGenerationVersion(): string
    {
        $version = trim((string) config('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3'));

        return $version !== '' ? $version : 'read-aloud-voice-pacing-v3';
    }

    protected function isStale(array $readAloud): bool
    {
        $audioUrl = trim((string) ($readAloud['audio_url'] ?? ''));
        if ($audioUrl === '') {
            return false;
        }

        $existing = trim((string) ($readAloud['generation_version'] ?? ''));

        return $existing !== $this->currentGenerationVersion();
    }
}
