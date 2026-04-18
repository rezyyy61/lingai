<?php

namespace Tests\Unit\Services\Lessons;

use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audio\LessonAudioChunkMerger;
use App\Services\AzureSpeech\AzureSpeechTtsTextService;
use App\Services\Lessons\GenerateLessonReadAloud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GenerateLessonReadAloudTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_and_persists_read_aloud_audio_from_original_text(): void
    {
        config()->set('lesson_generation.read_aloud.disk', 'public');
        config()->set('lesson_generation.read_aloud.directory', 'lessons');
        config()->set('lesson_generation.read_aloud.format', 'mp3');
        config()->set('lesson_generation.read_aloud.locale_fallback', 'en-US');
        config()->set('lesson_generation.read_aloud.voice_map', ['en-US' => 'en-US-JennyNeural']);
        config()->set('lesson_generation.read_aloud.rate', '-4%');
        config()->set('lesson_generation.read_aloud.style_map', ['en-US' => 'chat']);
        config()->set('lesson_generation.read_aloud.chunk_break_ms', 0);
        config()->set('lesson_generation.read_aloud.paragraph_break_ms', 520);
        config()->set('lesson_generation.read_aloud.sentence_break_ms', 180);
        config()->set('lesson_generation.read_aloud.chunk.max_chars', 80);
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-quality-v2');

        Storage::fake('public');

        $tts = Mockery::mock(AzureSpeechTtsTextService::class);
        $tts->shouldReceive('synthesizeSsml')
            ->twice()
            ->andReturn(
                'chunk-audio-1',
                'chunk-audio-2',
            );

        $merger = Mockery::mock(LessonAudioChunkMerger::class);
        $merger->shouldReceive('merge')
            ->once()
            ->with(Mockery::on(function (array $chunks) {
                return count($chunks) === 2
                    && (int) ($chunks[0]['pause_ms'] ?? -1) === 0
                    && (int) ($chunks[1]['pause_ms'] ?? -1) === 0;
            }), 'mp3')
            ->andReturn('merged-read-aloud');

        $this->app->instance(AzureSpeechTtsTextService::class, $tts);
        $this->app->instance(LessonAudioChunkMerger::class, $merger);

        $lesson = $this->createLesson([
            'analysis_meta' => [
                'existing' => 'value',
            ],
            'original_text' => 'Emma looked at her watch. She started walking faster because the train was about to leave.',
        ]);

        $result = app(GenerateLessonReadAloud::class)->handle($lesson);

        $this->assertNotNull($result->audio_path);
        $this->assertNotNull($result->audio_url);
        Storage::disk('public')->assertExists((string) $result->audio_path);
        $this->assertSame('ready', data_get($result->analysis_meta, 'read_aloud.status'));
        $this->assertSame('en-US-JennyNeural', data_get($result->analysis_meta, 'read_aloud.voice'));
        $this->assertSame('en-US', data_get($result->analysis_meta, 'read_aloud.locale'));
        $this->assertSame('-4%', data_get($result->analysis_meta, 'read_aloud.rate'));
        $this->assertSame('chat', data_get($result->analysis_meta, 'read_aloud.style'));
        $this->assertSame('mp3', data_get($result->analysis_meta, 'read_aloud.format'));
        $this->assertSame('read-aloud-quality-v2', data_get($result->analysis_meta, 'read_aloud.generation_version'));
        $this->assertSame(0, data_get($result->analysis_meta, 'read_aloud.config_snapshot.chunk_break_ms'));
        $this->assertSame(520, data_get($result->analysis_meta, 'read_aloud.config_snapshot.paragraph_break_ms'));
        $this->assertSame('value', data_get($result->analysis_meta, 'existing'));
        $this->assertGreaterThanOrEqual(2, (int) data_get($result->analysis_meta, 'read_aloud.chunk_count'));
        $this->assertSame(
            'Emma looked at her watch.',
            data_get($result->analysis_meta, 'read_aloud.chunks.0.text')
        );
        $this->assertSame('chunk', data_get($result->analysis_meta, 'read_aloud.chunks.0.type'));
        $this->assertFalse(data_get($result->analysis_meta, 'read_aloud.chunks.0.ends_paragraph'));
        $this->assertNull(data_get($result->analysis_meta, 'read_aloud.sync_precision'));
        $this->assertNull(data_get($result->analysis_meta, 'read_aloud.alignment_provider'));
        $this->assertNull(data_get($result->analysis_meta, 'read_aloud.word_timestamps'));
    }

    public function test_it_does_not_overwrite_existing_successful_read_aloud_data_when_generation_fails(): void
    {
        config()->set('lesson_generation.read_aloud.chunk.max_chars', 80);
        config()->set('lesson_generation.read_aloud.voice_map', []);

        $tts = Mockery::mock(AzureSpeechTtsTextService::class);
        $tts->shouldReceive('pickVoiceShortName')
            ->once()
            ->andReturn('en-US-JennyNeural');
        $tts->shouldReceive('synthesizeSsml')
            ->once()
            ->andThrow(new RuntimeException('TTS failed'));

        $this->app->instance(AzureSpeechTtsTextService::class, $tts);

        $lesson = $this->createLesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/old.mp3',
                ],
            ],
            'original_text' => 'Emma looked at her watch.',
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(GenerateLessonReadAloud::class)->handle($lesson);
        } finally {
            $fresh = $lesson->fresh();
            $this->assertSame('ready', data_get($fresh->analysis_meta, 'read_aloud.status'));
            $this->assertSame('/storage/lessons/1/read-aloud/old.mp3', data_get($fresh->analysis_meta, 'read_aloud.audio_url'));
        }
    }

    protected function createLesson(array $lessonOverrides = []): Lesson
    {
        $user = User::factory()->create();

        $workspace = Workspace::query()->create([
            'owner_id' => $user->id,
            'name' => 'Workspace',
            'slug' => 'workspace-read-aloud-' . uniqid(),
            'target_language' => 'en',
            'support_language' => 'fa',
        ]);

        return Lesson::query()->create(array_merge([
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
    }
}
