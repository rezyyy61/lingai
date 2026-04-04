<?php

namespace Tests\Feature\Lessons;

use App\Jobs\GenerateLessonAudioJob;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateLessonAudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_audio_generation_as_processing_and_dispatches_the_job(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'analysis_meta' => [
                'audio_script' => [
                    'spoken_segments' => [
                        [
                            'type' => 'intro',
                            'speaker' => 'coach',
                            'style' => 'friendly',
                            'pause_ms' => 700,
                            'text' => 'Bonjour et bienvenue.',
                        ],
                    ],
                ],
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/audio");

        $response
            ->assertAccepted()
            ->assertJson([
                'status' => 'processing',
            ]);

        $this->assertSame('processing', data_get($lesson->fresh()->analysis_meta, 'audio_generation.status'));

        Queue::assertPushed(GenerateLessonAudioJob::class, function (GenerateLessonAudioJob $job) use ($lesson) {
            return $job->lessonId === $lesson->id;
        });
    }

    public function test_it_allows_audio_generation_when_the_lesson_still_needs_script_generation(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'analysis_meta' => [],
            'original_text' => 'Bonjour tout le monde.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/audio");

        $response
            ->assertAccepted()
            ->assertJson([
                'status' => 'processing',
            ]);

        $this->assertSame('processing', data_get($lesson->fresh()->analysis_meta, 'audio_generation.status'));

        Queue::assertPushed(GenerateLessonAudioJob::class, function (GenerateLessonAudioJob $job) use ($lesson) {
            return $job->lessonId === $lesson->id;
        });
    }

    public function test_it_rejects_lessons_without_segments_when_original_text_is_missing(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'original_text' => '   ',
            'analysis_meta' => [],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/audio");

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lesson']);

        $this->assertNull(data_get($lesson->fresh()->analysis_meta, 'audio_generation.status'));
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_dispatch_a_duplicate_audio_job_when_processing(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'analysis_meta' => [
                'audio_script' => [
                    'spoken_segments' => [
                        [
                            'type' => 'intro',
                            'speaker' => 'coach',
                            'style' => 'friendly',
                            'pause_ms' => 400,
                            'text' => 'Bonjour.',
                        ],
                    ],
                ],
                'audio_generation' => [
                    'status' => 'processing',
                ],
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/audio");

        $response
            ->assertAccepted()
            ->assertJson([
                'status' => 'processing',
            ]);

        Queue::assertNothingPushed();
        $this->assertSame('processing', data_get($lesson->fresh()->analysis_meta, 'audio_generation.status'));
    }

    protected function createOwnedLesson(array $lessonOverrides = []): array
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'French Workspace',
            'slug' => 'french-workspace-audio-' . uniqid(),
            'target_language' => 'fr',
            'support_language' => 'en',
        ]);

        $lesson = Lesson::query()->create(array_merge([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Lesson',
            'resource_type' => 'text',
            'source_url' => null,
            'original_text' => 'Bonjour.',
            'language' => 'fr',
            'level' => null,
            'short_description' => 'Bonjour.',
            'status' => 'ready',
        ], $lessonOverrides));

        return [$user, $lesson];
    }
}
