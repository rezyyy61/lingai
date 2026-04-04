<?php

namespace Tests\Unit\Services\Lessons;

use App\Services\Lessons\LessonSentenceCandidateScorer;
use Tests\TestCase;

class LessonSentenceCandidateScorerTest extends TestCase
{
    public function test_it_scores_short_natural_sentences_higher_than_long_clause_heavy_ones(): void
    {
        $scorer = new LessonSentenceCandidateScorer();
        $fullText = 'I need a minute to think. The road was unusually crowded, and because the weather had suddenly changed while everyone was already late, the whole morning felt much harder than it needed to be.';

        $shortScore = $scorer->score([
            'text' => 'I need a minute to think.',
            'translation' => null,
        ], $fullText);

        $longScore = $scorer->score([
            'text' => 'Because the weather had suddenly changed while everyone was already late, the whole morning felt much harder than it needed to be.',
            'translation' => null,
        ], $fullText);

        $this->assertGreaterThan($longScore, $shortScore);
    }

    public function test_shortlist_prefers_diversity_over_near_duplicates(): void
    {
        $scorer = new LessonSentenceCandidateScorer();
        $fullText = 'I missed the train again. I missed the train again today. I need a minute to think. We can fix this tomorrow.';

        $shortlist = $scorer->shortlist([
            ['text' => 'I missed the train again.', 'translation' => null],
            ['text' => 'I missed the train again today.', 'translation' => null],
            ['text' => 'I need a minute to think.', 'translation' => null],
            ['text' => 'We can fix this tomorrow.', 'translation' => null],
        ], $fullText, 3);

        $texts = array_column($shortlist, 'text');

        $this->assertCount(3, $texts);
        $this->assertContains('I need a minute to think.', $texts);
        $this->assertContains('We can fix this tomorrow.', $texts);
        $this->assertTrue(
            in_array('I missed the train again.', $texts, true) xor in_array('I missed the train again today.', $texts, true)
        );
    }

    public function test_it_prefers_emotional_or_reflective_lines_over_visual_scene_description(): void
    {
        $scorer = new LessonSentenceCandidateScorer();
        $fullText = 'She felt angry and frustrated for a moment. Maybe this delay is not so bad after all. She quickly looked at the large screen. Her eyes moved from one line to another.';

        $emotionalScore = $scorer->score([
            'text' => 'She felt angry and frustrated for a moment.',
            'translation' => null,
        ], $fullText);

        $reflectiveScore = $scorer->score([
            'text' => 'Maybe this delay is not so bad after all.',
            'translation' => null,
        ], $fullText);

        $visualScore = $scorer->score([
            'text' => 'She quickly looked at the large screen.',
            'translation' => null,
        ], $fullText);

        $sceneScore = $scorer->score([
            'text' => 'Her eyes moved from one line to another.',
            'translation' => null,
        ], $fullText);

        $this->assertGreaterThan($visualScore, $emotionalScore);
        $this->assertGreaterThan($sceneScore, $reflectiveScore);
    }
}
