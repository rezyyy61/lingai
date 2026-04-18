<?php

namespace Tests\Unit\Services\Lessons;

use App\Models\Lesson;
use App\Services\Lessons\LessonReadAloudState;
use Tests\TestCase;

class LessonReadAloudStateTest extends TestCase
{
    public function test_it_marks_existing_audio_without_current_generation_version_as_stale(): void
    {
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3');

        $lesson = new Lesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/old.mp3',
                ],
            ],
        ]);

        $state = app(LessonReadAloudState::class)->get($lesson);

        $this->assertTrue($state['is_stale']);
        $this->assertSame('read-aloud-voice-pacing-v3', $state['current_generation_version']);
    }

    public function test_it_does_not_mark_current_generation_as_stale(): void
    {
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3');

        $lesson = new Lesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/current.mp3',
                    'generation_version' => 'read-aloud-voice-pacing-v3',
                ],
            ],
        ]);

        $state = app(LessonReadAloudState::class)->get($lesson);

        $this->assertFalse($state['is_stale']);
    }

    public function test_it_exposes_playback_state_without_sync_metadata(): void
    {
        config()->set('lesson_generation.read_aloud.generation_version', 'read-aloud-voice-pacing-v3');

        $lesson = new Lesson([
            'analysis_meta' => [
                'read_aloud' => [
                    'status' => 'ready',
                    'audio_url' => '/storage/lessons/1/read-aloud/current.mp3',
                    'voice' => 'en-US-GuyNeural',
                    'generation_version' => 'read-aloud-voice-pacing-v3',
                    'sync_precision' => 'word',
                    'alignment_provider' => 'azure_speech_sdk',
                    'word_timestamps' => [
                        ['text' => 'Hello', 'start' => 0, 'end' => 0.4],
                    ],
                ],
            ],
        ]);

        $state = app(LessonReadAloudState::class)->get($lesson);

        $this->assertSame('ready', $state['status']);
        $this->assertSame('/storage/lessons/1/read-aloud/current.mp3', $state['audio_url']);
        $this->assertSame('en-US-GuyNeural', $state['voice']);
        $this->assertArrayNotHasKey('sync_precision', $state);
        $this->assertArrayNotHasKey('alignment_provider', $state);
        $this->assertArrayNotHasKey('word_timestamps', $state);
        $this->assertArrayNotHasKey('timings', $state);
    }
}
