<?php

namespace App\Support;

use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

class LessonContentGeneration
{
    public static function currentStatus(Lesson $lesson, string $feature): ?string
    {
        return data_get(self::state($lesson, $feature), 'status');
    }

    public static function markProcessing(int $lessonId, string $feature, ?string $message = null): ?Lesson
    {
        return self::update($lessonId, $feature, function (array $state) use ($message): array {
            return array_merge($state, [
                'status' => 'processing',
                'message' => $message,
                'started_at' => $state['started_at'] ?? now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                'completed_at' => null,
                'failed_at' => null,
            ]);
        });
    }

    public static function markReady(int $lessonId, string $feature, array $extra = []): ?Lesson
    {
        return self::update($lessonId, $feature, function (array $state) use ($extra): array {
            return array_merge($state, $extra, [
                'status' => 'ready',
                'message' => $extra['message'] ?? null,
                'updated_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'failed_at' => null,
            ]);
        });
    }

    public static function markFailed(int $lessonId, string $feature, ?string $message = null): ?Lesson
    {
        return self::update($lessonId, $feature, function (array $state) use ($message): array {
            return array_merge($state, [
                'status' => 'failed',
                'message' => $message,
                'updated_at' => now()->toIso8601String(),
                'failed_at' => now()->toIso8601String(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function state(Lesson $lesson, string $feature): array
    {
        $state = data_get($lesson->analysis_meta, "content_generation.{$feature}");

        return is_array($state) ? $state : [];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    protected static function update(int $lessonId, string $feature, callable $mutator): ?Lesson
    {
        return DB::transaction(function () use ($lessonId, $feature, $mutator): ?Lesson {
            /** @var Lesson|null $lesson */
            $lesson = Lesson::query()
                ->whereKey($lessonId)
                ->lockForUpdate()
                ->first();

            if (! $lesson) {
                return null;
            }

            $meta = is_array($lesson->analysis_meta) ? $lesson->analysis_meta : [];
            $state = data_get($meta, "content_generation.{$feature}");
            $nextState = $mutator(is_array($state) ? $state : []);

            data_set($meta, "content_generation.{$feature}", $nextState);

            $lesson->forceFill([
                'analysis_meta' => $meta,
            ])->save();

            return $lesson;
        });
    }
}
