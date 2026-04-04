<?php

namespace Tests\Feature\Lessons;

use App\Jobs\GenerateLessonWordsJob;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateLessonWordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_the_generation_job_with_inline_prompt_and_replace_existing_flag(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'original_text' => 'Emma was in a hurry and almost out of breath when she reached the station.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/words/generate", [
            'inline_prompt' => 'Prefer reusable travel phrases.',
            'replace_existing' => false,
        ]);

        $response
            ->assertAccepted()
            ->assertJson([
                'status' => 'queued',
            ]);

        Queue::assertPushed(GenerateLessonWordsJob::class, function (GenerateLessonWordsJob $job) use ($lesson) {
            return $job->lesson->is($lesson)
                && $job->inlinePrompt === 'Prefer reusable travel phrases.'
                && $job->replaceExisting === false;
        });
    }

    public function test_it_rejects_generation_when_original_text_is_blank(): void
    {
        Queue::fake();

        [$user, $lesson] = $this->createOwnedLesson([
            'original_text' => '   ',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/lessons/{$lesson->id}/words/generate");

        $response
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Lesson has no original_text. Cannot generate words.',
            ]);

        Queue::assertNothingPushed();
    }

    protected function createOwnedLesson(array $lessonOverrides = []): array
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'English Workspace',
            'slug' => 'english-workspace-' . uniqid(),
            'target_language' => 'en',
            'support_language' => 'fa',
        ]);

        $lesson = Lesson::query()->create(array_merge([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Lesson',
            'resource_type' => 'text',
            'original_text' => 'Hello world.',
            'language' => 'en',
            'status' => 'draft',
        ], $lessonOverrides));

        return [$user, $lesson];
    }
}
