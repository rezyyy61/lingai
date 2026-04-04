<?php

namespace Tests\Feature\Lessons;

use App\Jobs\GenerateLessonAudioScriptJob;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateLessonAudioScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_the_lesson_as_processing_and_dispatches_the_job(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'status' => 'draft',
            'original_text' => 'Bonjour tout le monde. Aujourd\'hui nous allons parler de la routine du matin.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/audio-script");

        $response
            ->assertAccepted()
            ->assertJson([
                'status' => 'processing',
            ]);

        $this->assertSame('processing', $lesson->fresh()->status);

        Queue::assertPushed(GenerateLessonAudioScriptJob::class, function (GenerateLessonAudioScriptJob $job) use ($lesson) {
            return $job->lessonId === $lesson->id;
        });
    }

    public function test_it_rejects_lessons_without_original_text(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'original_text' => '   ',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/audio-script");

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lesson']);

        $this->assertSame('draft', $lesson->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_it_does_not_dispatch_a_duplicate_job_when_already_processing(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'status' => 'processing',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/audio-script");

        $response
            ->assertAccepted()
            ->assertJson([
                'status' => 'processing',
            ]);

        Queue::assertNothingPushed();
        $this->assertSame('processing', $lesson->fresh()->status);
    }

    protected function createOwnedLesson(array $lessonOverrides = []): array
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'French Workspace',
            'slug' => 'french-workspace-' . uniqid(),
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
            'status' => 'draft',
        ], $lessonOverrides));

        return [$user, $lesson];
    }
}
