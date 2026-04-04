<?php

namespace App\Services\Lessons;

use App\Services\Ai\LlmClient;
use App\Services\Ai\Pipelines\ChunkedPromptRunner;
use App\Services\Text\ChunkPlan;
use App\Services\Text\ChunkPolicy;
use App\Services\Text\TextChunker;
use Illuminate\Support\Facades\Log;

class LessonSentenceService
{
    protected LessonSentenceCandidateScorer $candidateScorer;

    public function __construct(
        protected LlmClient $llm,
        protected TextChunker $chunker,
        protected ChunkedPromptRunner $runner,
        ?LessonSentenceCandidateScorer $candidateScorer = null,
    ) {
        $this->candidateScorer = $candidateScorer ?? new LessonSentenceCandidateScorer();
    }

    /**
     * Shadowing sentences generator.
     *
     * Output:
     * [
     *   ['text' => '...', 'translation' => '...'],
     *   ...
     * ]
     */
    public function generate(string $text, string $targetLanguage = 'en', string $supportLanguage = 'en'): array
    {
        $provider = (string) config('services.openai.provider', 'openai');
        $fullText = $this->normalizeText($text);

        if ($fullText === '') {
            return [];
        }

        $wordCount = $this->wordCount($fullText);
        $minItems = (int) config('services.openai.shadowing_min_items', 12);
        $maxItems = (int) config('services.openai.shadowing_max_items', 20);
        $desired = max(3, min($maxItems, $this->suggestSentenceCount($fullText, $maxItems)));
        $requiredItems = $this->minimumAcceptedSentenceCount($desired, $minItems, $wordCount);

        $policy = $this->sentenceChunkPolicy();
        $chunkInput = $this->shrinkText($fullText, (int) config('services.openai.shadowing_max_chars', 7000));
        $plan = $this->chunker->plan($chunkInput, $policy);

        if ($plan->chunks === []) {
            return [];
        }

        $perChunkTarget = $this->planPerChunkCount(count($plan->chunks), $desired);
        $perChunkMin = max(2, (int) floor($perChunkTarget * 0.5));
        $options = $this->llmOptionsForSentences($provider);
        $logContext = [
            'pipeline' => 'lesson_sentences',
            'provider' => $provider,
            'desired' => $desired,
            'required_items' => $requiredItems,
            'min_items' => $minItems,
            'max_items' => $maxItems,
            'chunks' => count($plan->chunks),
            'per_chunk_target' => $perChunkTarget,
            'per_chunk_min' => $perChunkMin,
            'total_words' => $wordCount,
            'total_chars' => $plan->totalChars ?? null,
            'target_words' => $plan->targetWords ?? null,
            'overlap_words' => $plan->overlapWords ?? null,
            'time_budget_ms' => $policy->timeBudgetMs ?? null,
            'target_lang' => $targetLanguage,
            'support_lang' => $supportLanguage,
        ];

        $candidates = $this->extractChunkCandidates(
            plan: $plan,
            targetLanguage: $targetLanguage,
            supportLanguage: $supportLanguage,
            perChunkTarget: $perChunkTarget,
            perChunkMin: $perChunkMin,
            options: $options,
            logContext: $logContext,
        );

        $candidatePool = $this->mergeAndRankSentences(
            items: $candidates,
            target: $targetLanguage,
            support: $supportLanguage,
            fullText: $fullText,
            maxKeep: $this->candidateShortlistLimit($desired),
        );

        if ($this->needsCandidateFallback($candidatePool, $fullText, $desired, $requiredItems)) {
            $fallbackCandidates = $this->extractFullTextCandidates(
                text: $this->shrinkText($fullText, (int) config('services.openai.shadowing_final_text_max_chars', 9000)),
                targetLanguage: $targetLanguage,
                supportLanguage: $supportLanguage,
                count: max($desired + 4, $desired),
                minCount: min($requiredItems, $desired),
                options: $options,
                logContext: $logContext,
            );

            $candidatePool = $this->mergeAndRankSentences(
                items: array_merge($candidatePool, $fallbackCandidates),
                target: $targetLanguage,
                support: $supportLanguage,
                fullText: $fullText,
                maxKeep: $this->candidateShortlistLimit($desired),
            );
        }

        $shortlist = $this->candidateScorer->shortlist(
            $candidatePool,
            $fullText,
            $this->candidateShortlistLimit($desired)
        );

        $final = $this->runFinalSelection(
            fullText: $fullText,
            shortlist: $shortlist,
            targetLanguage: $targetLanguage,
            supportLanguage: $supportLanguage,
            desired: $desired,
            minItems: $requiredItems,
            options: $options,
            logContext: $logContext,
        );

        if (count($final) < $requiredItems) {
            Log::warning('LessonSentenceService: insufficient sentences', $logContext + [
                'items' => count($final),
                'need_at_least' => $requiredItems,
            ]);

            return [];
        }

        return array_values(array_slice($final, 0, $desired));
    }

    // ---------------------------
    // Stage A / Stage B
    // ---------------------------

    protected function extractChunkCandidates(
        ChunkPlan $plan,
        string $targetLanguage,
        string $supportLanguage,
        int $perChunkTarget,
        int $perChunkMin,
        array $options,
        array $logContext
    ): array {
        $all = [];
        $chunksTotal = count($plan->chunks);

        foreach ($plan->chunks as $i => $chunkText) {
            $chunkIndex = $i + 1;

            $singlePlan = new ChunkPlan(
                chunks: [$chunkText],
                targetWords: (int) ($plan->targetWords ?? 0),
                overlapWords: (int) ($plan->overlapWords ?? 0),
                totalWords: $this->wordCount($chunkText),
                totalChars: mb_strlen($chunkText),
            );

            $results = $this->runner->runJson(
                plan: $singlePlan,
                messagesFactory: function (string $t) use ($targetLanguage, $supportLanguage, $perChunkTarget, $perChunkMin, $chunkIndex, $chunksTotal) {
                    return [
                        [
                            'role' => 'system',
                            'content' => 'Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->promptSentences(
                                text: $t,
                                target: $targetLanguage,
                                support: $supportLanguage,
                                count: $perChunkTarget,
                                minCount: $perChunkMin,
                                chunkIndex: $chunkIndex,
                                chunksTotal: $chunksTotal,
                            ),
                        ],
                    ];
                },
                options: $options,
                logContext: $logContext + ['chunk' => $chunkIndex, 'chunks_total' => $chunksTotal],
            );

            foreach ($results as $result) {
                $items = data_get($result, 'json.sentences');

                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (is_array($item)) {
                        $all[] = $item;
                    }
                }
            }
        }

        return $all;
    }

    protected function extractFullTextCandidates(
        string $text,
        string $targetLanguage,
        string $supportLanguage,
        int $count,
        int $minCount,
        array $options,
        array $logContext
    ): array {
        if (trim($text) === '') {
            return [];
        }

        $plan = new ChunkPlan(
            chunks: [$text],
            targetWords: 0,
            overlapWords: 0,
            totalWords: $this->wordCount($text),
            totalChars: mb_strlen($text),
        );

        $results = $this->runner->runJson(
            plan: $plan,
            messagesFactory: function (string $t) use ($targetLanguage, $supportLanguage, $count, $minCount) {
                return [
                    [
                        'role' => 'system',
                        'content' => 'Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->promptSentences(
                            text: $t,
                            target: $targetLanguage,
                            support: $supportLanguage,
                            count: $count,
                            minCount: $minCount,
                            chunkIndex: 0,
                            chunksTotal: 0,
                        ),
                    ],
                ];
            },
            options: $options,
            logContext: $logContext + ['chunk' => 0, 'chunks_total' => 0, 'fallback' => true],
        );

        $items = [];

        foreach ($results as $result) {
            $sentences = data_get($result, 'json.sentences');

            if (! is_array($sentences)) {
                continue;
            }

            foreach ($sentences as $sentence) {
                if (is_array($sentence)) {
                    $items[] = $sentence;
                }
            }
        }

        return $items;
    }

    protected function runFinalSelection(
        string $fullText,
        array $shortlist,
        string $targetLanguage,
        string $supportLanguage,
        int $desired,
        int $minItems,
        array $options,
        array $logContext
    ): array {
        if ($shortlist === []) {
            return [];
        }

        $prompt = $this->promptFinalSentences(
            fullText: $this->shrinkText($fullText, (int) config('services.openai.shadowing_final_text_max_chars', 9000)),
            candidates: $shortlist,
            target: $targetLanguage,
            support: $supportLanguage,
            count: $desired,
            minCount: min($minItems, $desired),
        );

        $finalOptions = $options;
        $provider = (string) config('services.openai.provider', 'openai');
        $finalOptions['max_output_tokens'] = $provider === 'azure'
            ? (int) config('services.openai.shadowing_final_max_completion_tokens', 1400)
            : (int) config('services.openai.shadowing_final_max_tokens', 1400);
        $finalOptions['temperature'] = 0.1;
        $finalOptions['response_format'] = ['type' => 'json_object'];

        $result = $this->llm->chatJson([
            [
                'role' => 'system',
                'content' => 'Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ], $finalOptions);

        if ($result->ok && is_array($result->json) && is_array($result->json['sentences'] ?? null)) {
            $selected = $this->cleanSelectedSentences(
                items: $result->json['sentences'],
                support: $supportLanguage,
                fullText: $fullText,
                maxKeep: $desired,
            );

            if (count($selected) >= min($minItems, $desired)) {
                return $selected;
            }
        }

        Log::warning('LessonSentenceService: final selection fallback', $logContext + [
            'status' => $result->status,
            'error' => $result->error,
        ]);

        return $this->candidateScorer->shortlist($shortlist, $fullText, $desired);
    }

    // ---------------------------
    // LLM options / chunk policy
    // ---------------------------

    protected function llmOptionsForSentences(string $provider): array
    {
        $responseFormat = ['type' => 'json_object'];

        if ($provider === 'azure') {
            return [
                'azure_deployment' => (string) config('services.openai.azure_deployment', config('services.openai.azure_deployment_words')),
                'azure_api_version' => (string) config('services.openai.azure_api_version'),
                'azure_use_v1' => (bool) config('services.openai.azure_use_v1', true),
                'max_output_tokens' => (int) config('services.openai.shadowing_max_completion_tokens', 900),
                'temperature' => null,
                'response_format' => $responseFormat,
            ];
        }

        return [
            'model' => (string) config('services.openai.fast_model', 'gpt-4.1-mini'),
            'max_output_tokens' => (int) config('services.openai.shadowing_max_tokens', 1200),
            'temperature' => 0.2,
            'response_format' => $responseFormat,
        ];
    }

    protected function sentenceChunkPolicy(): ChunkPolicy
    {
        if (method_exists(ChunkPolicy::class, 'forSentences')) {
            return ChunkPolicy::forSentences();
        }

        return new ChunkPolicy(
            (int) config('services.openai.shadowing_chunk_target_words', config('services.openai.words_chunk_target_words', 450)),
            (int) config('services.openai.shadowing_chunk_overlap_words', config('services.openai.words_chunk_overlap_words', 12)),
            (int) config('services.openai.shadowing_chunk_max_chunks', config('services.openai.words_chunk_max_chunks', 6)),
            (int) config('services.openai.shadowing_time_budget_ms', config('services.openai.words_time_budget_ms', 55000)),
        );
    }

    // ---------------------------
    // Prompt
    // ---------------------------

    protected function translationLanguageGuard(string $supportCode): string
    {
        $m = $this->langMeta($supportCode);

        return <<<TXT
Translation rules (STRICT):
- "translation" must be written ONLY in {$m['label']} ({$m['native']}) language.
- Do NOT include any words, letters, or phrases from any other language.
- Use the normal writing system/script used by {$m['label']}.
- If you are not 100% sure you can produce correct {$m['label']}, set "translation" to "".
TXT;
    }

    protected function promptSentences(
        string $text,
        string $target,
        string $support,
        int $count,
        int $minCount,
        int $chunkIndex,
        int $chunksTotal
    ): string {
        $targetMeta = $this->langMeta($target);
        $guard = $this->translationLanguageGuard($support);
        $chunkLine = $chunksTotal > 0 ? "Chunk: {$chunkIndex}/{$chunksTotal}" : 'Chunk: full text';

        return <<<TXT
You are collecting candidate shadowing sentences for a language-learning app.
This is Stage A: collect strong candidates only. Do not pad with weak lines.

Return ONLY valid JSON. No markdown. No extra keys. No extra text.

Schema (exact):
{"sentences":[{"text":"","translation":""}]}

Goal:
Find short or medium sentences that are genuinely worth repeating aloud.
Choose lines that feel memorable, natural, emotionally clear, and useful for speaking fluency.

Strongly prefer:
- short action sentences
- feeling or emotion sentences
- useful everyday expressions
- cause/effect sentences
- reflection or takeaway sentences
- naturally spoken lines
- clean dialogue lines when they are useful and easy to repeat
- lines with strong rhythm, learner value, and memorable wording

Best kinds of shadowing lines:
- a useful expression a learner might reuse in real life
- a clear feeling, reaction, decision, or realization
- a line that sounds good when repeated several times
- a sentence that teaches fluency, not just comprehension

Strongly avoid:
- long clause-heavy lines
- plain descriptive lines unless they have unusually strong speaking rhythm
- low-value visual description
- scene scanning narration
- mechanically descriptive movement
- weak storytelling glue with little practice value
- valid but forgettable lines
- broken fragments, quote fragments, or noisy punctuation
- low-information lines that only describe what was seen on a screen, board, door, object, or background detail
- lines that are grammatical but not especially useful to repeat aloud

Selection rules:
- Use ONLY lesson content from this chunk.
- Keep "text" ONLY in {$targetMeta['label']} ({$targetMeta['native']}).
- Prefer 5-14 words. Accept 4-18 if strong. Do not exceed 20 words unless unavoidable.
- If a long sentence contains a good shadowing-ready sub-sentence, extract that shorter natural sentence.
- If two lines are both valid, choose the one with more learner value, emotion, action, reflection, or reusable expression value.
- It is better to return fewer strong candidates than weak ones.
- Avoid near-duplicates in wording or meaning.
- Keep punctuation simple and easy to read aloud.
{$guard}

Count:
- Return up to {$count} strong candidates.
- Aim for at least {$minCount} only if the chunk truly contains that many good options.

{$chunkLine}

Text:
{$text}
TXT;
    }

    protected function promptFinalSentences(
        string $fullText,
        array $candidates,
        string $target,
        string $support,
        int $count,
        int $minCount
    ): string {
        $targetMeta = $this->langMeta($target);
        $guard = $this->translationLanguageGuard($support);

        return <<<TXT
You are the final curator for shadowing practice in a language-learning app.
This is Stage B: choose the best global set of shadowing sentences from the lesson.

Return ONLY valid JSON. No markdown. No extra keys. No extra text.

Schema (exact):
{"sentences":[{"text":"","translation":""}]}

Product goal:
Select the best short, natural, memorable sentences for shadowing practice.

Choose sentences that are:
- natural to say aloud
- easy to imitate
- short to medium length
- rhythmically good
- semantically clear
- useful for pronunciation and fluency practice
- memorable and reusable
- strong in action, feeling, reflection, or expression value

Global selection rules:
- Prioritize the best overall shadowing set, not merely valid sentences.
- Prefer variety across actions, feelings, cause/effect, everyday expressions, and useful speaking patterns.
- Avoid near-duplicates in wording, structure, or meaning.
- Prefer 5-14 words. Accept 4-18 if strong. Do not exceed 20 words unless unavoidable.
- Use ONLY the lesson content. If needed, you may refine a candidate into a shorter exact natural sentence supported by the full text.
- Prefer lines a learner would actually benefit from repeating several times.
- If two lines are both valid, choose the more memorable, reusable, speakable, and learner-useful one.
- Prefer action, feeling, reflection, takeaway, dialogue, and useful everyday expression lines.
- Deprioritize low-value visual description, scene scanning narration, mechanically descriptive movement, and weak filler narration.
- Reject filler, meta, narration-heavy glue, awkward quote pieces, fragments, and noisy punctuation.
- Keep "text" ONLY in {$targetMeta['label']} ({$targetMeta['native']}).
{$guard}

Count:
- Return EXACTLY {$count} sentences when possible.
- If truly impossible, return as many strong sentences as possible, but at least {$minCount}.

Candidate sentences:
{$this->renderCandidatesForPrompt($candidates)}

Full lesson text:
{$fullText}
TXT;
    }

    protected function renderCandidatesForPrompt(array $candidates): string
    {
        $lines = [];

        foreach (array_values($candidates) as $index => $candidate) {
            $payload = [
                'text' => (string) ($candidate['text'] ?? ''),
                'translation' => (string) ($candidate['translation'] ?? ''),
            ];

            $lines[] = ($index + 1) . '. ' . (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    // ---------------------------
    // Cleaning / ranking
    // ---------------------------

    protected function mergeAndRankSentences(array $items, string $target, string $support, string $fullText, int $maxKeep): array
    {
        $byKey = [];

        foreach ($items as $item) {
            $candidate = $this->normalizeSentenceCandidate($item, $support);

            if ($candidate === null) {
                continue;
            }

            $score = $this->candidateScorer->score($candidate, $fullText);

            if ($score < $this->minimumQualityScore()) {
                continue;
            }

            $candidate['_quality_score'] = $score;
            $key = $this->sentenceKey($candidate['text']);
            $existing = $byKey[$key] ?? null;

            if ($existing === null || $score > ($existing['_quality_score'] ?? PHP_INT_MIN) || $this->candidateHasBetterTranslation($candidate, $existing)) {
                $byKey[$key] = $candidate;
            }
        }

        return $this->candidateScorer->shortlist(array_values($byKey), $fullText, $maxKeep);
    }

    protected function normalizeSentenceCandidate(mixed $item, string $support): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $text = trim((string) ($item['text'] ?? $item['sentence'] ?? ''));
        $translation = trim((string) ($item['translation'] ?? ''));

        if ($text === '') {
            return null;
        }

        $text = $this->cleanSentenceText($text);

        if ($text === '') {
            return null;
        }

        if ($this->looksLikeJunk($text) || $this->looksLikeFragment($text) || ! $this->sentenceLengthOk($text) || ! $this->punctuationComplexityOk($text)) {
            return null;
        }

        if ($translation !== '') {
            $translation = $this->cleanSentenceText($translation);

            if (! $this->translationLooksValidForSupport($translation, $support)) {
                $translation = '';
            }
        }

        return [
            'text' => $text,
            'translation' => $translation !== '' ? $translation : null,
        ];
    }

    protected function cleanSelectedSentences(array $items, string $support, string $fullText, int $maxKeep): array
    {
        $out = [];
        $seen = [];

        foreach ($items as $item) {
            $candidate = $this->normalizeSentenceCandidate($item, $support);

            if ($candidate === null) {
                continue;
            }

            if ($this->candidateScorer->score($candidate, $fullText) < $this->minimumFinalSelectionScore()) {
                continue;
            }

            $key = $this->sentenceKey($candidate['text']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $candidate;

            if (count($out) >= $maxKeep) {
                break;
            }
        }

        return $out;
    }

    protected function candidateHasBetterTranslation(array $candidate, array $existing): bool
    {
        $candidateTranslation = trim((string) ($candidate['translation'] ?? ''));
        $existingTranslation = trim((string) ($existing['translation'] ?? ''));

        if ($candidateTranslation === $existingTranslation) {
            return $this->wordCount((string) ($candidate['text'] ?? '')) < $this->wordCount((string) ($existing['text'] ?? ''));
        }

        if ($candidateTranslation !== '' && $existingTranslation === '') {
            return true;
        }

        if ($candidateTranslation === '') {
            return false;
        }

        return mb_strlen($candidateTranslation) > mb_strlen($existingTranslation);
    }

    protected function needsCandidateFallback(array $candidatePool, string $fullText, int $desired, int $requiredItems): bool
    {
        if (count($candidatePool) < max(3, min($desired, $requiredItems))) {
            return true;
        }

        $neededHighQuality = max(1, min($desired, max($requiredItems, 3)));

        return $this->candidateScorer->highQualityCount(
            $candidatePool,
            $fullText,
            $this->minimumHighQualityScore()
        ) < $neededHighQuality;
    }

    protected function minimumQualityScore(): int
    {
        return 6;
    }

    protected function minimumHighQualityScore(): int
    {
        return 45;
    }

    protected function minimumFinalSelectionScore(): int
    {
        return 18;
    }

    protected function candidateShortlistLimit(int $desired): int
    {
        return max($desired, (int) config('services.openai.shadowing_final_candidate_limit', 32));
    }

    protected function cleanSentenceText(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/^\[music\]\s*/iu', '', $s) ?? $s;
        $s = preg_replace('/\s*\[music\]$/iu', '', $s) ?? $s;
        $s = preg_replace('/^[\-\*\•\·\d\.\)\(]+\s+/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s, " \t\n\r\0\x0B\"“”‘’'«»");

        return trim($s);
    }

    protected function looksLikeJunk(string $text): bool
    {
        $t = mb_strtolower($text);

        $bad = [
            'welcome back',
            'subscribe',
            'like and subscribe',
            'episode of',
            'english pod',
            'englishpod.com',
            'go to our website',
            'thanks for listening',
            'until next time',
            'goodbye',
            'vocabulary preview',
            'language takeaway',
            'slow down',
            'in this lesson',
            'today we are going to',
            'let us get started',
            'thanks for watching',
            'click the link',
            'follow us',
            'this video',
            'this episode',
        ];

        foreach ($bad as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }

        if (preg_match('/https?:\/\/\S+/iu', $text)) {
            return true;
        }

        if (preg_match('/\b\d{1,2}:\d{2}\b/u', $text)) {
            return true;
        }

        if (preg_match('/\.{3,}/u', $text)) {
            return true;
        }

        return false;
    }

    protected function looksLikeFragment(string $text): bool
    {
        if (! preg_match('/\p{L}/u', $text)) {
            return true;
        }

        $trimmed = trim($text);
        $lower = mb_strtolower($trimmed);

        if (preg_match('/^[,;:\-\)\]]/u', $trimmed) || preg_match('/[,;:\-\(\[]$/u', $trimmed)) {
            return true;
        }

        if (! $this->hasBalancedPairs($trimmed)) {
            return true;
        }

        if (preg_match('/^(and|but|or|because|when|while|although|if)\b/iu', $lower)) {
            return true;
        }

        if (preg_match('/\b(and|but|or|because|when|while|although|if)$/iu', $lower)) {
            return true;
        }

        return false;
    }

    protected function punctuationComplexityOk(string $text): bool
    {
        $commaCount = preg_match_all('/,/u', $text) ?: 0;
        $complexMarks = preg_match_all('/[,:;()\[\]{}]/u', $text) ?: 0;
        $quoteCount = preg_match_all('/["“”‘’«»]/u', $text) ?: 0;

        if ($commaCount > 2 || $complexMarks > 4) {
            return false;
        }

        if ($quoteCount === 1) {
            return false;
        }

        return true;
    }

    protected function sentenceLengthOk(string $text): bool
    {
        $length = mb_strlen($text);

        if ($length < 8 || $length > 180) {
            return false;
        }

        $wordCount = $this->wordCount($text);

        if ($wordCount >= 2) {
            if ($wordCount < 4) {
                return false;
            }

            if ($wordCount > 20) {
                return false;
            }
        }

        return true;
    }

    protected function sentenceKey(string $text): string
    {
        $t = mb_strtolower($text);
        $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    protected function translationLooksValidForSupport(string $translation, string $supportCode): bool
    {
        $translation = trim($translation);

        if ($translation === '') {
            return false;
        }

        $code = strtolower(trim($supportCode));

        if (! in_array($code, ['fa', 'ar', 'ur'], true) && preg_match('/\p{Arabic}/u', $translation)) {
            return false;
        }

        if ($code === 'fa') {
            $arabicMarkers = ['ة', 'ى', 'ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ'];
            $hits = 0;

            foreach ($arabicMarkers as $marker) {
                if (str_contains($translation, $marker)) {
                    $hits++;
                }
            }

            $arabicWords = [' كانت ', ' كان ', ' التي ', ' الذي ', ' هذا ', ' هذه ', ' على ', ' إلى ', ' من ', ' في ', ' و '];

            foreach ($arabicWords as $word) {
                if (str_contains(' ' . $translation . ' ', $word)) {
                    $hits++;
                }
            }

            if ($hits >= 2) {
                return false;
            }
        }

        return true;
    }

    protected function hasBalancedPairs(string $text): bool
    {
        return (substr_count($text, '(') === substr_count($text, ')'))
            && (substr_count($text, '[') === substr_count($text, ']'))
            && ((preg_match_all('/["“”«»]/u', $text) ?: 0) % 2 === 0);
    }

    // ---------------------------
    // Helpers
    // ---------------------------

    protected function suggestSentenceCount(string $text, int $max): int
    {
        $n = $this->wordCount($text);

        $desired = 18;

        if ($n <= 70) {
            $desired = 4;
        } elseif ($n <= 100) {
            $desired = 6;
        } elseif ($n <= 130) {
            $desired = 8;
        } elseif ($n <= 190) {
            $desired = 10;
        } elseif ($n <= 260) {
            $desired = 12;
        } elseif ($n <= 500) {
            $desired = 14;
        } elseif ($n <= 900) {
            $desired = 16;
        }

        return max(3, min($max, $desired));
    }

    protected function minimumAcceptedSentenceCount(int $desired, int $configuredMin, int $wordCount): int
    {
        if ($wordCount <= 190) {
            return 1;
        }

        if ($desired < $configuredMin) {
            return max(3, $desired);
        }

        return min($configuredMin, $desired);
    }

    protected function planPerChunkCount(int $chunks, int $desiredTotal): int
    {
        if ($chunks <= 1) {
            return $desiredTotal;
        }

        $base = (int) ceil($desiredTotal / $chunks);

        return max(
            (int) config('services.openai.shadowing_min_per_chunk', 4),
            min((int) config('services.openai.shadowing_max_per_chunk', 8), $base)
        );
    }

    protected function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    protected function wordCount(string $text): int
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($t === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $t);

        return is_array($parts) ? count($parts) : 0;
    }

    protected function shrinkText(string $text, int $maxChars): string
    {
        $text = $this->normalizeText($text);

        if ($maxChars <= 0 || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $paragraphs = $this->splitParagraphs($text);

        if (count($paragraphs) <= 1) {
            return $this->truncateBySentences($text, $maxChars);
        }

        $selected = [];
        $picked = $this->spreadIndices(count($paragraphs), min(count($paragraphs), max(3, intdiv($maxChars, 800) + 1)));

        foreach ($picked as $index) {
            $selected[$index] = true;
        }

        $ordered = array_keys($selected);
        sort($ordered);

        $pieces = [];
        $used = 0;
        $separatorLength = 2;
        $remainingSlots = max(1, count($ordered));

        foreach ($ordered as $index) {
            $remainingBudget = $maxChars - $used;

            if ($remainingBudget <= 24) {
                break;
            }

            $share = max(48, intdiv($remainingBudget, $remainingSlots));
            $piece = $this->truncateBySentences($paragraphs[$index], min($remainingBudget, $share));

            if ($piece === '') {
                $remainingSlots--;
                continue;
            }

            $extra = $pieces === [] ? 0 : $separatorLength;

            if (($used + $extra + mb_strlen($piece)) > $maxChars) {
                $piece = $this->truncateBySentences($paragraphs[$index], $maxChars - $used - $extra);
            }

            if ($piece === '') {
                $remainingSlots--;
                continue;
            }

            $pieces[] = $piece;
            $used += $extra + mb_strlen($piece);
            $remainingSlots--;
        }

        $shrunk = trim(implode("\n\n", $pieces));

        if ($shrunk === '') {
            return $this->truncateBySentences($text, $maxChars);
        }

        return $shrunk;
    }

    protected function splitParagraphs(string $text): array
    {
        $parts = preg_split("/\n{2,}/u", $text) ?: [];

        return array_values(array_filter(array_map(static fn (string $part) => trim($part), $parts), static fn (string $part) => $part !== ''));
    }

    protected function splitSentences(string $text): array
    {
        $parts = preg_split('/(?<=[\.\!\?\。\؟])(?:["”’\']+)?\s+/u', trim($text)) ?: [];
        $sentences = array_values(array_filter(array_map(static fn (string $part) => trim($part), $parts), static fn (string $part) => $part !== ''));

        return $sentences !== [] ? $sentences : [trim($text)];
    }

    protected function truncateBySentences(string $text, int $maxChars): string
    {
        $text = trim($text);

        if ($text === '' || $maxChars <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $sentences = $this->splitSentences($text);
        $picked = $this->spreadIndices(count($sentences), min(count($sentences), max(2, intdiv($maxChars, 110))));
        $selected = [];

        foreach ($picked as $index) {
            $selected[$index] = true;
        }

        $result = [];
        $used = 0;

        foreach (array_keys($selected) as $index) {
            $sentence = $sentences[$index] ?? '';

            if ($sentence === '') {
                continue;
            }

            $extra = $result === [] ? 0 : 1;

            if (($used + $extra + mb_strlen($sentence)) > $maxChars) {
                continue;
            }

            $result[] = $sentence;
            $used += $extra + mb_strlen($sentence);
        }

        if ($result === []) {
            return trim(mb_substr($text, 0, $maxChars));
        }

        return trim(implode(' ', $result));
    }

    protected function spreadIndices(int $count, int $pickCount): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($pickCount >= $count) {
            return range(0, $count - 1);
        }

        $indices = [];

        for ($i = 0; $i < $pickCount; $i++) {
            $position = (int) round(($i * ($count - 1)) / max(1, $pickCount - 1));
            $indices[$position] = true;
        }

        $out = array_keys($indices);
        sort($out);

        return $out;
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
                'direction' => 'ltr',
            ];
        }

        return [
            'code' => $code,
            'label' => (string) ($meta['label'] ?? $code),
            'native' => (string) ($meta['native'] ?? $code),
            'direction' => (string) ($meta['direction'] ?? 'ltr'),
        ];
    }
}
