<?php

namespace Tests\Unit\Services\Lessons;

use App\Services\Lessons\ReadAloudTextChunker;
use Tests\TestCase;

class ReadAloudTextChunkerTest extends TestCase
{
    public function test_it_chunks_text_by_paragraph_and_sentence_boundaries(): void
    {
        config()->set('lesson_generation.read_aloud.chunk.max_chars', 90);

        $text = <<<TXT
The Missed Train.

Emma looked at her watch for the third time in less than a minute. It was already 8:15, and her train was supposed to leave at 8:20. She started walking faster.

When she finally reached the station, she was out of breath. She looked at the departures board and searched for platform 4.
TXT;

        $chunks = app(ReadAloudTextChunker::class)->chunk($text);

        $this->assertGreaterThanOrEqual(3, count($chunks));
        $this->assertSame('The Missed Train.', $chunks[0]);

        foreach ($chunks as $chunk) {
            $this->assertNotSame('', trim($chunk));
            $this->assertLessThanOrEqual(90, mb_strlen($chunk));
        }
    }

    public function test_it_marks_only_real_paragraph_boundaries_for_pauses(): void
    {
        config()->set('lesson_generation.read_aloud.chunk.max_chars', 90);

        $text = <<<TXT
Emma looked at her watch. She started walking faster because the train was about to leave. The platform was still far away.

When she finally reached the station, she was out of breath.
TXT;

        $chunks = app(ReadAloudTextChunker::class)->chunkWithMetadata($text);

        $this->assertGreaterThanOrEqual(3, count($chunks));
        $this->assertFalse($chunks[0]['ends_paragraph']);
        $this->assertTrue($chunks[1]['ends_paragraph']);
        $this->assertTrue($chunks[array_key_last($chunks)]['ends_paragraph']);
        $this->assertSame(0, $chunks[0]['paragraph_index']);
        $this->assertSame(1, $chunks[array_key_last($chunks)]['paragraph_index']);
    }
}
