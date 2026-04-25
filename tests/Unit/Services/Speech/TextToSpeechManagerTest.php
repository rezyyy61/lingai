<?php

namespace Tests\Unit\Services\Speech;

use App\Services\Speech\TextToSpeechManager;
use Tests\TestCase;

class TextToSpeechManagerTest extends TestCase
{
    public function test_it_returns_elevenlabs_for_read_aloud_when_elevenlabs_is_the_active_provider(): void
    {
        config()->set('services.tts.provider', 'elevenlabs');
        config()->set('services.tts.fallback_provider', 'azure');

        $manager = app(TextToSpeechManager::class);

        $this->assertSame('elevenlabs', $manager->providerFor('lesson_read_aloud')->providerName());
    }
}
