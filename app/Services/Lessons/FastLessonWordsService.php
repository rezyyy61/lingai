<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\Ai\LlmClient;
use App\Services\Ai\Pipelines\ChunkedPromptRunner;
use App\Services\Text\ChunkPlan;
use App\Services\Text\ChunkPolicy;
use App\Services\Text\TextChunker;
use Illuminate\Support\Facades\Log;

class FastLessonWordsService
{
    public function __construct(
        protected LlmClient $llm,
        protected TextChunker $chunker,
        protected ChunkedPromptRunner $runner,
        protected LessonWordPromptBuilder $promptBuilder,
        protected LessonWordCandidateScorer $candidateScorer,
    ) {}

    public function generate(Lesson $lesson, ?string $inlinePrompt = null): array
    {
        $provider = (string) config('services.openai.provider', 'openai');
        $targetLanguage = (string) ($lesson->target_language ?? 'en');
        $supportLanguage = (string) ($lesson->support_language ?? 'en');
        $instructionContext = $this->promptBuilder->build($lesson, $inlinePrompt);

        $rawText = (string) $lesson->original_text;

        // 1) Critical: decode entities + remove tags + normalize whitespace
        $fullText = $this->prepareLessonText($rawText);
        if ($fullText === '') {
            return [];
        }

        // 2) Bounds / desired count
        $minItems = (int) config('services.openai.words_min_items', 10);
        $maxItems = (int) config('services.openai.words_max_items', 24);

        $desired = $this->suggestWordItemCount($fullText, $minItems);
        $desired = max($minItems, min($maxItems, $desired));

        // 3) Chunk plan (shared infra)
        $policy = ChunkPolicy::forWords();
        $maxChars = (int) config('services.openai.words_max_chars', 6000);

        $shrunk = $this->shrinkText($fullText, $maxChars);
        $plan = $this->chunker->plan($shrunk, $policy);

        if (empty($plan->chunks)) {
            return [];
        }

        $perChunkTarget = $this->planPerChunkCount(count($plan->chunks), $desired);
        $perChunkMin = max(3, (int) floor($perChunkTarget * 0.6));

        $options = $this->llmOptionsForWords($provider);

        $all = [];

        // 4) Stage A: chunk-level candidate extraction
        foreach ($plan->chunks as $i => $chunkText) {
            $chunkIndex = $i + 1;

            $singlePlan = new ChunkPlan(
                chunks: [$chunkText],
                targetWords: $plan->targetWords,
                overlapWords: $plan->overlapWords,
                totalWords: $this->wordCount($chunkText),
                totalChars: mb_strlen($chunkText)
            );

            $results = $this->runner->runJson(
                plan: $singlePlan,
                messagesFactory: function (string $t) use ($lesson, $targetLanguage, $supportLanguage, $perChunkTarget, $perChunkMin, $chunkIndex, $plan, $instructionContext) {
                    return [
                        [
                            'role' => 'system',
                            'content' => 'Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->promptBuilder->buildCandidateExtractionPrompt(
                                lesson: $lesson,
                                text: $t,
                                target: $targetLanguage,
                                support: $supportLanguage,
                                count: $perChunkTarget,
                                minCount: $perChunkMin,
                                chunkIndex: $chunkIndex,
                                chunksTotal: count($plan->chunks),
                                instructionContext: $instructionContext,
                            ),
                        ],
                    ];
                },
                options: $options,
                logContext: [
                    'pipeline' => 'lesson_words',
                    'provider' => $provider,
                    'chunk' => $chunkIndex,
                    'chunks_total' => count($plan->chunks),
                    'desired' => $desired,
                    'min_items' => $minItems,
                    'max_items' => $maxItems,
                ]
            );

            foreach ($results as $r) {
                $words = data_get($r, 'json.words');
                if (!is_array($words)) continue;

                foreach ($words as $w) {
                    if (!is_array($w)) continue;
                    $all[] = $w;
                }
            }
        }

        // 5) Fallback candidate extraction from full text if pool is still too thin
        $candidatePool = $this->mergeAndRankWords(
            words: $all,
            fullText: $fullText,
            desiredCount: max($desired, (int) config('services.openai.words_final_candidate_limit', 36)),
            targetLanguage: $targetLanguage,
            supportLanguage: $supportLanguage
        );

        if (count($candidatePool) < min($minItems, $desired)) {
            $fallbackText = $this->shrinkText($fullText, $maxChars);

            $fallbackPlan = new ChunkPlan(
                chunks: [$fallbackText],
                targetWords: 0,
                overlapWords: 0,
                totalWords: $this->wordCount($fallbackText),
                totalChars: mb_strlen($fallbackText)
            );

            $fallbackResults = $this->runner->runJson(
                plan: $fallbackPlan,
                messagesFactory: function (string $t) use ($lesson, $targetLanguage, $supportLanguage, $desired, $minItems, $instructionContext, $maxItems) {
                    return [
                        [
                            'role' => 'system',
                            'content' => 'Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->promptBuilder->buildCandidateExtractionPrompt(
                                lesson: $lesson,
                                text: $t,
                                target: $targetLanguage,
                                support: $supportLanguage,
                                count: max($desired, min($maxItems, $desired + 4)),
                                minCount: min($minItems, $desired),
                                chunkIndex: 0,
                                chunksTotal: 0,
                                instructionContext: $instructionContext,
                            ),
                        ],
                    ];
                },
                options: $options,
                logContext: [
                    'pipeline' => 'lesson_words',
                    'provider' => $provider,
                    'chunk' => 0,
                    'chunks_total' => 0,
                    'desired' => $desired,
                    'min_items' => $minItems,
                    'max_items' => $maxItems,
                    'fallback' => true,
                ]
            );

            $more = [];
            foreach ($fallbackResults as $r) {
                $words = data_get($r, 'json.words');
                if (!is_array($words)) continue;

                foreach ($words as $w) {
                    if (!is_array($w)) continue;
                    $more[] = $w;
                }
            }

            $candidatePool = $this->mergeAndRankWords(
                words: array_merge($candidatePool, $more),
                fullText: $fullText,
                desiredCount: max($desired, (int) config('services.openai.words_final_candidate_limit', 36)),
                targetLanguage: $targetLanguage,
                supportLanguage: $supportLanguage
            );
        }

        // 6) Stage B: global pedagogical shortlist + final full-text selection
        $shortlist = $this->candidateScorer->shortlist(
            $candidatePool,
            $fullText,
            (int) config('services.openai.words_final_candidate_limit', 36)
        );

        $final = $this->runFinalSelection(
            lesson: $lesson,
            fullText: $fullText,
            shortlist: $shortlist,
            targetLanguage: $targetLanguage,
            supportLanguage: $supportLanguage,
            desired: $desired,
            minItems: $minItems,
            instructionContext: $instructionContext,
            options: $options,
        );

        if (count($final) < min($minItems, $desired)) {
            Log::warning('FastLessonWordsService: insufficient results', [
                'pipeline' => 'lesson_words',
                'provider' => $provider,
                'desired' => $desired,
                'min_items' => $minItems,
                'got' => count($final),
            ]);
            return [];
        }

        return array_values(array_slice($final, 0, $desired));
    }

    /**
     * LLM Options (must be compatible with your OpenAiLlmClient)
     */
    protected function llmOptionsForWords(string $provider): array
    {
        // Always pass response_format as OBJECT (array) not string.
        $responseFormat = ['type' => 'json_object'];

        if ($provider === 'azure') {
            return [
                'azure_deployment' => (string) config('services.openai.azure_deployment_words'),
                'azure_api_version' => (string) config('services.openai.azure_api_version'),
                'azure_use_v1' => (bool) config('services.openai.azure_use_v1', true),
                'use_max_completion_tokens' => (bool) config('services.openai.azure_words_use_max_completion_tokens', true),

                // Token controls
                'max_output_tokens' => (int) config('services.openai.words_max_completion_tokens', 700),

                // o4 / azure models may restrict temperature; your client already guards it.
                'temperature' => 0.2,

                'response_format' => $responseFormat,
            ];
        }

        return [
            'model' => (string) config('services.openai.fast_model', 'gpt-4.1-mini'),
            'max_output_tokens' => (int) config('services.openai.words_max_tokens', 900),
            'temperature' => 0.2,
            'response_format' => $responseFormat,
        ];
    }

    protected function runFinalSelection(
        Lesson $lesson,
        string $fullText,
        array $shortlist,
        string $targetLanguage,
        string $supportLanguage,
        int $desired,
        int $minItems,
        ?string $instructionContext,
        array $options
    ): array {
        if ($shortlist === []) {
            return [];
        }

        $prompt = $this->promptBuilder->buildFinalSelectionPrompt(
            lesson: $lesson,
            fullText: $this->shrinkText($fullText, (int) config('services.openai.words_final_text_max_chars', 9000)),
            candidates: $shortlist,
            target: $targetLanguage,
            support: $supportLanguage,
            count: $desired,
            minCount: min($minItems, $desired),
            instructionContext: $instructionContext,
        );

        $finalOptions = $options;
        $isAzureProvider = (string) config('services.openai.provider', 'openai') === 'azure';
        $finalOptions['max_output_tokens'] = $isAzureProvider
            ? (int) config('services.openai.words_final_max_completion_tokens', 1200)
            : (int) config('services.openai.words_final_max_tokens', 1200);
        $finalOptions['temperature'] = 0.1;
        $finalOptions['response_format'] = ['type' => 'json_object'];

        $res = $this->llm->chatJson([
            [
                'role' => 'system',
                'content' => 'Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ], $finalOptions);

        if ($res->ok && is_array($res->json) && is_array($res->json['words'] ?? null)) {
            $selected = $this->mergeAndRankWords(
                words: $res->json['words'],
                fullText: $fullText,
                desiredCount: $desired,
                targetLanguage: $targetLanguage,
                supportLanguage: $supportLanguage
            );

            if (count($selected) >= min($minItems, $desired)) {
                return $selected;
            }
        }

        Log::warning('FastLessonWordsService: final selection fallback', [
            'lesson_id' => $lesson->id,
            'status' => $res->status,
            'error' => $res->error,
        ]);

        return $this->candidateScorer->shortlist($shortlist, $fullText, $desired);
    }

    /**
     * Merge + normalize + dedupe + validate + canonicalize
     */
    protected function mergeAndRankWords(array $words, string $fullText, int $desiredCount, string $targetLanguage, string $supportLanguage): array
    {
        $full = $this->prepareLessonText($fullText);
        $fullLower = mb_strtolower($full);

        $byKey = [];

        foreach ($words as $w) {
            if (!is_array($w)) continue;

            $termRaw = trim((string) ($w['term'] ?? ''));
            if ($termRaw === '') continue;

            // Coerce to EXACT substring from the text (case/spacing correction)
            $term = $this->coerceTermToExactFromText($termRaw, $full);

            if (!$this->termExistsInText($term, $full)) {
                continue;
            }

            $item = [
                'term' => $term,
                'meaning' => $this->cleanField($w['meaning'] ?? null),
                'example_sentence' => $this->cleanField($w['example_sentence'] ?? null),
                'translation' => $this->cleanField($w['translation'] ?? null),
            ];

            // Validate + language-guard (don’t pollute DB with wrong language)
            $item = $this->sanitizeAndValidateItem($item, $targetLanguage, $supportLanguage);
            if ($item === null) {
                continue;
            }

            $key = $this->normalizeTermKey($item['term']);

            if (!isset($byKey[$key])) {
                $byKey[$key] = $item;
                continue;
            }

            // pick better between duplicates
            $byKey[$key] = $this->pickBetterWordItem($byKey[$key], $item, $fullLower);
        }

        $kept = array_values($byKey);

        // Canonical teaching fixes (English lessons)
        if (strtolower(trim($targetLanguage)) === 'en') {
            $kept = $this->enforceCanonicalEnglishTerms($kept);
        }

        $kept = $this->candidateScorer->shortlist($kept, $fullText, $desiredCount);

        return array_values(array_slice($kept, 0, $desiredCount));
    }

    protected function sanitizeAndValidateItem(array $item, string $targetLanguage, string $supportLanguage): ?array
    {
        $term = trim((string) ($item['term'] ?? ''));
        if ($term === '') return null;

        $meaning = $this->cleanField($item['meaning'] ?? null);
        $example = $this->cleanField($item['example_sentence'] ?? null);
        $translation = $this->cleanField($item['translation'] ?? null);

        // Meaning & example must exist for learning value
        if ($meaning === '' || $example === '') {
            return null;
        }

        // Enforce target language on meaning/example (best-effort strict)
        if (!$this->isTextInLanguage($meaning, $targetLanguage)) {
            return null;
        }
        if (!$this->isTextInLanguage($example, $targetLanguage)) {
            return null;
        }

        // Enforce support language on translation. If wrong => blank (don’t poison DB)
        if ($translation !== '' && !$this->isTextInLanguage($translation, $supportLanguage)) {
            $translation = '';
        }

        return [
            'term' => $term,
            'meaning' => $meaning,
            'example_sentence' => $example,
            'translation' => $translation,
        ];
    }

    /**
     * Canonical rules for common teaching mistakes in English lessons.
     * If both wrong & right exist => drop wrong.
     */
    protected function enforceCanonicalEnglishTerms(array $items): array
    {
        $map = [
            'isle' => 'aisle',
        ];

        $index = [];
        foreach ($items as $i => $it) {
            $t = strtolower(trim((string) ($it['term'] ?? '')));
            $index[$t] = $i;
        }

        foreach ($map as $wrong => $right) {
            if (isset($index[$wrong]) && isset($index[$right])) {
                unset($items[$index[$wrong]]);
            }
        }

        return array_values($items);
    }

    /**
     * Choose better duplicate item.
     */
    protected function pickBetterWordItem(array $a, array $b, string $fullLower): array
    {
        $ta = (string) ($a['term'] ?? '');
        $tb = (string) ($b['term'] ?? '');

        $fa = $ta !== '' ? substr_count($fullLower, mb_strtolower($ta)) : 0;
        $fb = $tb !== '' ? substr_count($fullLower, mb_strtolower($tb)) : 0;

        if ($fa !== $fb) {
            return $fa > $fb ? $a : $b;
        }

        // Prefer spaced phrase over glued word if same meaning (ring bearer vs ringbearer)
        $sa = str_contains($ta, ' ') ? 1 : 0;
        $sb = str_contains($tb, ' ') ? 1 : 0;
        if ($sa !== $sb) {
            return $sa > $sb ? $a : $b;
        }

        // Prefer longer term slightly (often more specific)
        $la = mb_strlen($ta);
        $lb = mb_strlen($tb);
        if ($la !== $lb) {
            return $la > $lb ? $a : $b;
        }

        // Prefer non-empty translation if one has it
        $ea = trim((string) ($a['translation'] ?? '')) !== '' ? 1 : 0;
        $eb = trim((string) ($b['translation'] ?? '')) !== '' ? 1 : 0;
        if ($ea !== $eb) {
            return $ea > $eb ? $a : $b;
        }

        return $a;
    }

    /**
     * Make term match EXACT substring in full text (fixes Married vs married, etc.)
     */
    protected function coerceTermToExactFromText(string $term, string $fullText): string
    {
        $term = trim($term);
        if ($term === '' || $fullText === '') return $term;

        $quoted = preg_quote($term, '/');

        // Case-sensitive exact match
        if (preg_match('/(?<![\p{L}\p{N}])(' . $quoted . ')(?![\p{L}\p{N}])/u', $fullText, $m)) {
            return (string) $m[1];
        }

        // Case-insensitive: return the matched exact substring from the text
        if (preg_match('/(?<![\p{L}\p{N}])(' . $quoted . ')(?![\p{L}\p{N}])/iu', $fullText, $m)) {
            return (string) $m[1];
        }

        return $term;
    }

    protected function termExistsInText(string $term, string $text): bool
    {
        $term = trim($term);
        if ($term === '') return false;

        $t = $this->prepareLessonText($text);
        if ($t === '') return false;

        $quoted = preg_quote($term, '/');
        $pattern = '/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/iu';

        return (bool) preg_match($pattern, $t);
    }

    /**
     * Language checks (strict enough to prevent Arabic leaking into fa output).
     * Not perfect, but practical and safe for your case.
     */
    protected function isTextInLanguage(string $text, string $langCode): bool
    {
        $text = trim($text);
        if ($text === '') return true;

        $code = strtolower(trim($langCode));

        // Latin languages: require majority of letters to be Latin
        $latinLangs = ['en','nl','de','fr','es','it','pt','pl','sv','no','da','tr'];
        if (in_array($code, $latinLangs, true)) {
            $letters = preg_match_all('/\p{L}/u', $text) ?: 0;
            if ($letters === 0) return true;

            $latin = preg_match_all('/\p{Latin}/u', $text) ?: 0;
            return ($latin / max(1, $letters)) >= 0.85;
        }

        if ($code === 'fa') {
            // Reject Arabic diacritics (very common in Arabic outputs, uncommon in Persian)
            if (preg_match('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', $text)) {
                return false;
            }

            // Reject very common Arabic stopwords/patterns
            $arabicSignals = [
                'كانت','كان','هذا','هذه','الذي','التي','في','من','إلى','على','مع','ثم','لكن','لذلك','بالأخير','بالاخير','وصيفة',
            ];
            foreach ($arabicSignals as $sig) {
                if (mb_strpos($text, $sig) !== false) {
                    return false;
                }
            }

            // Must be mostly Arabic-script letters (Persian uses Arabic script)
            $letters = preg_match_all('/\p{L}/u', $text) ?: 0;
            if ($letters === 0) return true;

            $arabic = preg_match_all('/\p{Arabic}/u', $text) ?: 0;
            return ($arabic / max(1, $letters)) >= 0.75;
        }

        if ($code === 'ar' || $code === 'ur') {
            $letters = preg_match_all('/\p{L}/u', $text) ?: 0;
            if ($letters === 0) return true;

            $arabic = preg_match_all('/\p{Arabic}/u', $text) ?: 0;
            return ($arabic / max(1, $letters)) >= 0.75;
        }

        if ($code === 'ru') {
            $letters = preg_match_all('/\p{L}/u', $text) ?: 0;
            if ($letters === 0) return true;

            $cyr = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;
            return ($cyr / max(1, $letters)) >= 0.85;
        }

        if ($code === 'hi') {
            $letters = preg_match_all('/\p{L}/u', $text) ?: 0;
            if ($letters === 0) return true;

            $dev = preg_match_all('/\p{Devanagari}/u', $text) ?: 0;
            return ($dev / max(1, $letters)) >= 0.85;
        }

        if ($code === 'ja') {
            return (bool) preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text);
        }

        if ($code === 'ko') {
            return (bool) preg_match('/\p{Hangul}/u', $text);
        }

        if ($code === 'zh') {
            return (bool) preg_match('/\p{Han}/u', $text);
        }

        // Unknown => don’t block
        return true;
    }

    protected function normalizeTermKey(string $term): string
    {
        $t = mb_strtolower(trim($term));

        // Remove punctuation except spaces/hyphens
        $t = preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $t) ?? $t;
        $t = preg_replace('/[\s\-]+/u', ' ', $t) ?? $t;
        $t = trim($t);

        // Remove spaces to dedupe ring bearer vs ringbearer
        $t2 = str_replace(' ', '', $t);

        // Simple plural trim (English-ish)
        $t2 = preg_replace('/(ies|es|s)$/u', '', $t2) ?? $t2;

        return $t2;
    }

    protected function cleanField(mixed $v): string
    {
        $s = trim((string) ($v ?? ''));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Critical: decode HTML entities + remove tags + normalize whitespace.
     */
    protected function prepareLessonText(string $text): string
    {
        $t = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = strip_tags($t);

        // Remove simple stage markers
        $t = preg_replace('/\[(music|Music)\]/u', ' ', $t) ?? $t;

        // Normalize whitespace
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    protected function shrinkText(string $text, int $maxChars): string
    {
        $t = $this->prepareLessonText($text);

        if ($maxChars <= 0) return $t;
        if (mb_strlen($t) <= $maxChars) return $t;

        $half = (int) floor($maxChars / 2);
        $head = mb_substr($t, 0, $half);
        $tail = mb_substr($t, -$half);

        return trim($head) . "\n...\n" . trim($tail);
    }

    protected function wordCount(string $text): int
    {
        $t = $this->prepareLessonText($text);
        if ($t === '') return 0;
        $parts = preg_split('/\s+/u', $t);
        return is_array($parts) ? count($parts) : 0;
    }

    protected function suggestWordItemCount(string $text, int $minItems): int
    {
        $n = $this->wordCount($text);

        if ($n <= 120) return max($minItems, 14);
        if ($n <= 260) return max($minItems, 18);
        if ($n <= 420) return max($minItems, 20);
        if ($n <= 650) return max($minItems, 22);

        return max($minItems, 24);
    }

    protected function planPerChunkCount(int $chunks, int $desiredTotal): int
    {
        if ($chunks <= 1) return $desiredTotal;

        $base = (int) ceil($desiredTotal / $chunks);

        $minPer = (int) config('services.openai.words_min_per_chunk', 5);
        $maxPer = (int) config('services.openai.words_max_per_chunk', 10);

        return max($minPer, min($maxPer, $base));
    }

}
