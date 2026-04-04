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

    public function buildCandidateExtractionPrompt(
        Lesson $lesson,
        string $text,
        string $target,
        string $support,
        int $count,
        int $minCount,
        int $chunkIndex,
        int $chunksTotal,
        ?string $instructionContext = null
    ): string {
        $targetMeta = $this->langMeta($target);
        $supportMeta = $this->langMeta($support);
        $instructionBlock = $instructionContext ? "Teacher preferences:\n{$instructionContext}\n\n" : '';
        $chunkLine = $chunksTotal > 0
            ? "Chunk: {$chunkIndex}/{$chunksTotal}"
            : "Chunk: full text";

        return <<<TXT
You are building a high-quality learner flashcard deck from a lesson text.

Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.

Schema (exact):
{"words":[{"term":"","meaning":"","example_sentence":"","translation":""}]}

This is candidate extraction, not the final deck.
Select ONLY terms and short phrases from this chunk that are genuinely worth considering for the final flashcard deck.
Keep the output compact. Save detailed teaching polish for the final selection stage.

Prioritize:
- reusable everyday vocabulary
- short phrases learners can actively use
- context-important expressions needed to understand the lesson
- items that would make strong flashcards for a learner

Avoid:
- weak generic nouns with low teaching value
- proper names, brands, dates, raw numbers
- trivial local details
- grammar glue like "of the", "in the", "it was"
- long clauses or full sentences
- items that are technically present in the text but not pedagogically useful

Hard constraints:
- Return EXACTLY {$count} items if possible, otherwise at least {$minCount}.
- Every "term" MUST appear in the provided chunk EXACTLY as written.
- "term" must be 1 to 5 words.
- Prefer strong phrases over isolated weak nouns when the phrase is more teachable.
- Keep only candidates you would seriously consider for the final deck.

Meaning:
- "meaning" must be a concise learner-friendly explanation in {$targetMeta['label']} ({$targetMeta['native']}).
- Keep it very short, ideally 3 to 8 words.

Example sentence:
- "example_sentence" must be a NEW natural sentence in {$targetMeta['label']} ({$targetMeta['native']}).
- It should show the same meaning clearly and naturally.
- Keep it short and simple.

Translation:
- "translation" must be in {$supportMeta['label']} ({$supportMeta['native']}).
- For candidate extraction, set "translation" to "" for almost all items.
- Only include a translation if it is genuinely needed and still very short.

{$instructionBlock}{$chunkLine}

Lesson chunk:
{$text}
TXT;
    }

    public function buildFinalSelectionPrompt(
        Lesson $lesson,
        string $fullText,
        array $candidates,
        string $target,
        string $support,
        int $count,
        int $minCount,
        ?string $instructionContext = null
    ): string {
        $targetMeta = $this->langMeta($target);
        $supportMeta = $this->langMeta($support);
        $instructionBlock = $instructionContext ? "Teacher preferences:\n{$instructionContext}\n\n" : '';
        $candidateList = $this->formatCandidateList($candidates);

        return <<<TXT
You are selecting the FINAL learner flashcard deck for a lesson.

Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.

Schema (exact):
{"words":[{"term":"","meaning":"","example_sentence":"","translation":""}]}

Goal:
Choose the BEST possible learner flashcard deck from the full lesson.
Behave like a language teacher, not a keyword extractor.

Prioritize:
- high-value everyday vocabulary
- reusable phrases learners can use in real life
- expressions that unlock understanding of the lesson
- items that are memorable, teachable, and worth reviewing

Strongly avoid:
- mediocre but valid nouns
- local story details with weak reuse value
- proper names
- weak generic words
- items that are less useful than a stronger phrase candidate

Selection rules:
- Return EXACTLY {$count} items if possible, otherwise at least {$minCount}.
- Prefer phrase-like items when they are more useful than single words.
- Every "term" MUST appear in the full lesson text EXACTLY as written.
- You may use the candidate shortlist below as your primary pool.
- If one candidate is valid but another is clearly more pedagogically useful, choose the better one.
- Output the most useful global deck, not the most frequent chunk-local terms.

Meaning:
- "meaning" must be a concise learner-friendly explanation in {$targetMeta['label']} ({$targetMeta['native']}).

Example sentence:
- "example_sentence" must be a NEW natural sentence in {$targetMeta['label']} ({$targetMeta['native']}).

Translation:
- "translation" must be in {$supportMeta['label']} ({$supportMeta['native']}).
- Translate the whole example sentence naturally.
- Format exactly as:
  "{translated example_sentence} ({translated meaning of term})"

{$instructionBlock}Candidate shortlist:
{$candidateList}

Full lesson text:
{$fullText}
TXT;
    }

    protected function formatCandidateList(array $candidates): string
    {
        if ($candidates === []) {
            return '- none';
        }

        $lines = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $term = trim((string) ($candidate['term'] ?? ''));
            $meaning = trim((string) ($candidate['meaning'] ?? ''));

            if ($term === '') {
                continue;
            }

            $lines[] = $meaning !== ''
                ? "- {$term} => {$meaning}"
                : "- {$term}";
        }

        return $lines !== [] ? implode("\n", $lines) : '- none';
    }

    protected function langMeta(string $code): array
    {
        $code = strtolower(trim($code));
        $supported = (array) config('learning_languages.supported', []);
        $meta = $supported[$code] ?? null;

        if (! is_array($meta)) {
            return [
                'code' => $code,
                'label' => $code,
                'native' => $code,
            ];
        }

        return [
            'code' => $code,
            'label' => (string) ($meta['label'] ?? $code),
            'native' => (string) ($meta['native'] ?? $code),
        ];
    }
}
