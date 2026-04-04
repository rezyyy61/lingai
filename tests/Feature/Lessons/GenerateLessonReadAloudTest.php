<?php

namespace Tests\Feature\Lessons;

use App\Jobs\GenerateLessonReadAloudJob;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateLessonReadAloudTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_read_aloud_as_processing_and_dispatches_the_job(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'analysis_meta' => [],
            'original_text' => 'Emma looked at her watch and started walking faster.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/read-aloud");

        $response
            ->assertAccepted()
            ->assertJson([
                'status' => 'processing',
            ]);

        $this->assertSame('processing', data_get($lesson->fresh()->analysis_meta, 'read_aloud.status'));

        Queue::assertPushed(GenerateLessonReadAloudJob::class, function (GenerateLessonReadAloudJob $job) use ($lesson) {
            return $job->lessonId === $lesson->id;
        });
    }

    public function test_it_rejects_lessons_without_original_text(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'original_text' => '   ',
            'analysis_meta' => [],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/read-aloud");

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lesson']);

        $this->assertNull(data_get($lesson->fresh()->analysis_meta, 'read_aloud.status'));
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_dispatch_a_duplicate_job_when_read_aloud_is_processing(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'processing',
                ],
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/read-aloud");

        $response
            ->assertAccepted()
            ->assertJson([
                'status' => 'processing',
            ]);

        Queue::assertNothingPushed();
        $this->assertSame('processing', data_get($lesson->fresh()->analysis_meta, 'read_aloud.status'));
    }

    protected function createOwnedLesson(array $lessonOverrides = []): array
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'English Workspace',
            'slug' => 'english-workspace-read-' . uniqid(),
            'target_language' => 'en',
            'support_language' => 'fa',
        ]);

        $lesson = Lesson::query()->create(array_merge([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Lesson',
            'resource_type' => 'text',
            'source_url' => null,
            'original_text' => 'Emma looked at her watch.',
            'language' => 'en',
            'level' => 'B1',
            'short_description' => 'Story',
            'status' => 'ready',
        ], $lessonOverrides));

        return [$user, $lesson];
    }
}
