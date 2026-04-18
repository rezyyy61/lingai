<?php

namespace Tests\Unit\Services\Speech;

use App\Services\Speech\ReadAloudSsmlBuilder;
use Tests\TestCase;

class ReadAloudSsmlBuilderTest extends TestCase
{
    public function test_it_builds_valid_ssml_with_voice_rate_and_optional_style(): void
    {
        $ssml = app(ReadAloudSsmlBuilder::class)->build(
            text: 'Hello there. This is a test chunk.',
            locale: 'en-US',
            voice: 'en-US-JennyNeural',
            rate: '-4%',
            style: 'friendly',
            sentenceBreakMs: 200,
            paragraphBreakMs: 500,
        );

        $this->assertStringContainsString('<speak', $ssml);
        $this->assertStringContainsString('xml:lang="en-US"', $ssml);
        $this->assertStringContainsString('voice name="en-US-JennyNeural"', $ssml);
        $this->assertStringContainsString('prosody rate="-4%"', $ssml);
        $this->assertStringContainsString('mstts:express-as style="friendly"', $ssml);
        $this->assertStringContainsString('<break time="200ms"/>', $ssml);
        $this->assertStringContainsString('<p><s>Hello there.</s>', $ssml);
        $this->assertStringContainsString('<s>Hello there.</s>', $ssml);
    }

    public function test_it_preserves_paragraph_structure_with_configurable_paragraph_breaks(): void
    {
        $ssml = app(ReadAloudSsmlBuilder::class)->build(
            text: "First paragraph.\n\nSecond paragraph.",
            locale: 'en-US',
            voice: 'en-US-JennyNeural',
            rate: '-2%',
            style: null,
            sentenceBreakMs: 120,
            paragraphBreakMs: 640,
        );

        $this->assertStringContainsString('<p><s>First paragraph.</s></p><break time="640ms"/><p><s>Second paragraph.</s></p>', $ssml);
    }
}
