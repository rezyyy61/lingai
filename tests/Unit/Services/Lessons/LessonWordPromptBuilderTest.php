<?php

namespace Tests\Unit\Services\Lessons;

use App\Models\Lesson;
use App\Services\Lessons\LessonWordPromptBuilder;
use Tests\TestCase;

class LessonWordPromptBuilderTest extends TestCase
{
    public function test_it_builds_a_final_selection_prompt_that_prioritizes_global_pedagogical_selection(): void
    {
        $lesson = new Lesson([
            'word_prompt_level' => 'B1',
            'word_prompt_notes' => 'Prefer travel vocabulary.',
        ]);

        $builder = new LessonWordPromptBuilder();

        $prompt = $builder->buildFinalSelectionPrompt(
            lesson: $lesson,
            fullText: 'Emma was in a hurry and almost out of breath when she reached the station.',
            candidates: [
                ['term' => 'station', 'meaning' => 'a place where trains stop'],
                ['term' => 'in a hurry', 'meaning' => 'moving quickly because there is not much time'],
            ],
            target: 'en',
            support: 'fa',
            count: 8,
            minCount: 6,
            instructionContext: $builder->build($lesson),
        );

        $this->assertStringContainsString('Behave like a language teacher, not a keyword extractor.', $prompt);
        $this->assertStringContainsString('Prefer phrase-like items when they are more useful than single words.', $prompt);
        $this->assertStringContainsString('Candidate shortlist:', $prompt);
        $this->assertStringContainsString('- in a hurry => moving quickly because there is not much time', $prompt);
    }
}
