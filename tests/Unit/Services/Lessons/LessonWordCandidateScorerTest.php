<?php

namespace Tests\Unit\Services\Lessons;

use App\Services\Lessons\LessonWordCandidateScorer;
use Tests\TestCase;

class LessonWordCandidateScorerTest extends TestCase
{
    public function test_it_prefers_reusable_phrases_over_weak_single_nouns(): void
    {
        $scorer = new LessonWordCandidateScorer();
        $text = 'Emma was in a hurry. She was in a hurry again when she reached the station in a hurry.';

        $weakNoun = [
            'term' => 'station',
            'meaning' => 'a place where trains stop',
            'example_sentence' => 'The station is busy in the morning.',
            'translation' => '',
        ];

        $strongPhrase = [
            'term' => 'in a hurry',
            'meaning' => 'moving quickly because there is not much time',
            'example_sentence' => 'I left the house in a hurry.',
            'translation' => '',
        ];

        $this->assertGreaterThan(
            $scorer->score($weakNoun, $text),
            $scorer->score($strongPhrase, $text)
        );
    }
}
