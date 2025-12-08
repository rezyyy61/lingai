<?php

namespace App\Services\Lessons;

use App\Models\Lesson;

class LessonWordPromptBuilder
{
    public function build(Lesson $lesson, ?string $inlinePrompt = null): ?string
    {
        $level = $lesson->word_prompt_level ?: null;
        $domain = $lesson->word_prompt_domain ?: null;
        $min = $lesson->word_prompt_min_items ?: null;
        $max = $lesson->word_prompt_max_items ?: null;
        $notes = $lesson->word_prompt_notes ?: null;

        if (! $level && ! $domain && ! $min && ! $max && ! $notes && ! $inlinePrompt) {
            return null;
        }

        $parts = [];

        $parts[] = 'You are selecting vocabulary items for flashcards.';

        if ($level) {
            $parts[] = 'Learner level: ' . $level . '.';
        }

        if ($domain) {
            $parts[] = 'Focus domain: ' . $domain . '.';
        }

        if ($min || $max) {
            $range = trim(($min ?: '') . '–' . ($max ?: ''), '–');
            if ($range !== '') {
                $parts[] = 'Approximate number of vocabulary items: ' . $range . '.';
            }
        }

        $parts[] = 'Selection preferences:';
        $parts[] = '- Prefer words and phrases that are useful for this learner level and appear in the source text.';
        $parts[] = '- Skip names of people, places, brands, numbers and dates, unless they are very important for understanding the text.';
        $parts[] = '- Avoid extremely rare or technical words unless the focus domain requires them.';
        $parts[] = '- Prefer items that can be reused in many everyday situations, not only in this one text.';
        $parts[] = '- If there are not enough suitable items, return fewer, but never invent words that are not present in the original text.';

        if ($notes) {
            $parts[] = 'Additional preferences from the teacher or course designer:';
            $parts[] = $notes;
        }

        if ($inlinePrompt) {
            $parts[] = 'Additional user instructions for this specific generation call:';
            $parts[] = $inlinePrompt;
        }

        return implode("\n", $parts);
    }
}
