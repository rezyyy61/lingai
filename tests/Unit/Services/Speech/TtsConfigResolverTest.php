<?php

namespace Tests\Unit\Services\Speech;

use App\Services\Speech\TtsConfigResolver;
use Tests\TestCase;

class TtsConfigResolverTest extends TestCase
{
    public function test_it_resolves_shared_default_english_tts_config(): void
    {
        $resolver = app(TtsConfigResolver::class);

        $this->assertSame('en-US', $resolver->localeForLanguage('en'));
        $this->assertSame('en-US-GuyNeural', $resolver->voiceForLocale('en-US'));
        $this->assertNull($resolver->styleForLocale('en-US'));
        $this->assertSame('-8%', $resolver->rate());
        $this->assertSame('audio-24khz-160kbitrate-mono-mp3', $resolver->outputFormat());
        $this->assertSame('read-aloud-voice-pacing-v3', $resolver->generationVersion());
    }

    public function test_it_builds_provider_metadata_snapshot(): void
    {
        $snapshot = app(TtsConfigResolver::class)->configSnapshot(
            feature: 'practice_flashcard',
            locale: 'en-US',
            voice: 'en-US-GuyNeural',
            style: null,
            outputFormat: 'audio-24khz-160kbitrate-mono-mp3',
            extra: ['preset' => 'beginner'],
        );

        $this->assertSame('practice_flashcard', $snapshot['feature']);
        $this->assertSame('read-aloud-voice-pacing-v3', $snapshot['version']);
        $this->assertSame('azure_speech_rest', $snapshot['provider']);
        $this->assertSame('en-US-GuyNeural', $snapshot['voice']);
        $this->assertSame('-8%', $snapshot['rate']);
        $this->assertSame('beginner', $snapshot['preset']);
    }
}
