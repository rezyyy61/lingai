<?php

namespace Tests\Unit\Services\Lessons;

use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audio\LessonAudioChunkMerger;
use App\Services\Lessons\GenerateLessonAudio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class GenerateLessonAudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_and_stores_lesson_audio_from_spoken_segments(): void
    {
        config()->set('services.azure_speech.key', 'test-key');
        config()->set('services.azure_speech.region', 'swedencentral');
        config()->set('lesson_generation.audio.disk', 'public');
        config()->set('lesson_generation.audio.directory', 'lessons');
        config()->set('lesson_generation.audio.format', 'mp3');
        config()->set('lesson_generation.audio.speakers', [
            'coach' => 'fr-FR-DeniseNeural',
            'helper' => 'fr-FR-HenriNeural',
        ]);

        Storage::fake('public');

        Http::fake([
            'https://swedencentral.api.cognitive.microsoft.com/sts/v1.0/issueToken' => Http::response('fake-token', 200),
            'https://swedencentral.tts.speech.microsoft.com/cognitiveservices/v1' => Http::response(str_repeat('A', 512), 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $merger = Mockery::mock(LessonAudioChunkMerger::class);
        $merger
            ->shouldReceive('merge')
            ->once()
            ->with(Mockery::on(function (array $chunks) {
                return count($chunks) === 2
                    && (int) ($chunks[0]['pause_ms'] ?? -1) === 700
                    && (int) ($chunks[1]['pause_ms'] ?? -1) === 500;
            }), 'mp3')
            ->andReturn('merged-audio-binary');

        $this->app->instance(LessonAudioChunkMerger::class, $merger);

        $lesson = $this->createLesson([
            'analysis_meta' => [
                'existing' => 'value',
                'audio_script' => [
                    'spoken_segments' => [
                        [
                            'type' => 'intro',
                            'speaker' => 'coach',
                            'style' => 'friendly',
                            'pause_ms' => 700,
                            'text' => 'Bonjour et bienvenue.',
                        ],
                        [
                            'type' => 'question',
                            'speaker' => 'helper',
                            'style' => 'gentle',
                            'pause_ms' => 500,
                            'text' => 'Pourquoi cette routine est-elle utile ?',
                        ],
                    ],
                    'source_language_code' => 'fr',
                ],
                'audio_generation' => [
                    'status' => 'processing',
                ],
            ],
        ]);

        $result = app(GenerateLessonAudio::class)->handle($lesson);

        $this->assertNotNull($result->audio_path);
        $this->assertNotNull($result->audio_url);
        Storage::disk('public')->assertExists((string) $result->audio_path);
        $this->assertSame('ready', data_get($result->analysis_meta, 'audio_generation.status'));
        $this->assertSame('fr-FR-DeniseNeural', data_get($result->analysis_meta, 'audio_generation.voice'));
        $this->assertSame('fr-FR-HenriNeural', data_get($result->analysis_meta, 'audio_generation.voice_map.helper'));
        $this->assertSame('mp3', data_get($result->analysis_meta, 'audio_generation.format'));
        $this->assertNotNull(data_get($result->analysis_meta, 'audio_generation.generated_at'));
        $this->assertSame(2, data_get($result->analysis_meta, 'audio_generation.segment_count'));
        $this->assertSame('value', data_get($result->analysis_meta, 'existing'));
        $this->assertCount(2, data_get($result->analysis_meta, 'audio_script.spoken_segments', []));
    }

    protected function createLesson(array $lessonOverrides = []): Lesson
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'Workspace',
            'slug' => 'workspace-audio-' . uniqid(),
            'target_language' => 'fr',
            'support_language' => 'en',
        ]);

        return Lesson::query()->create(array_merge([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Lesson',
            'resource_type' => 'text',
            'source_url' => null,
            'original_text' => 'Je me reveille a sept heures.',
            'language' => 'fr',
            'level' => null,
            'short_description' => 'Routine du matin',
            'status' => 'ready',
        ], $lessonOverrides));
    }
}
