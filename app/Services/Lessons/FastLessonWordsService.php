<?php

namespace App\Services\Lessons;

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
        protected ChunkedPromptRunner $runner
    ) {}

    public function generate(string $text, string $targetLanguage = 'en', string $supportLanguage = 'en'): array
    {
        $provider = (string) config('services.openai.provider', 'openai');

        $rawText = (string) $text;

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
        $merged = [];

        // 4) Iterate chunks, stop early when enough
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
                messagesFactory: function (string $t) use ($targetLanguage, $supportLanguage, $perChunkTarget, $perChunkMin, $chunkIndex, $plan) {
                    return [
                        [
                            'role' => 'system',
                            'content' => 'Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->promptWords(
                                text: $t,
                                target: $targetLanguage,
                                support: $supportLanguage,
                                count: $perChunkTarget,
                                minCount: $perChunkMin,
                                chunkIndex: $chunkIndex,
                                chunksTotal: count($plan->chunks),
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

            $merged = $this->mergeAndRankWords(
                words: $all,
                fullText: $fullText,
                desiredCount: $desired,
                targetLanguage: $targetLanguage,
                supportLanguage: $supportLanguage
            );

            if (count($merged) >= $desired) {
                break;
            }
        }

        // 5) Fallback: one full-text pass if still insufficient
        if (count($merged) < min($minItems, $desired)) {
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
                messagesFactory: function (string $t) use ($targetLanguage, $supportLanguage, $desired, $minItems) {
                    return [
                        [
                            'role' => 'system',
                            'content' => 'Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->promptWords(
                                text: $t,
                                target: $targetLanguage,
                                support: $supportLanguage,
                                count: $desired,
                                minCount: min($minItems, $desired),
                                chunkIndex: 0,
                                chunksTotal: 0
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

            $merged = $this->mergeAndRankWords(
                words: array_merge($merged, $more),
                fullText: $fullText,
                desiredCount: $desired,
                targetLanguage: $targetLanguage,
                supportLanguage: $supportLanguage
            );
        }

        // 6) Final guard
        if (count($merged) < min($minItems, $desired)) {
            Log::warning('FastLessonWordsService: insufficient results', [
                'pipeline' => 'lesson_words',
                'provider' => $provider,
                'desired' => $desired,
                'min_items' => $minItems,
                'got' => count($merged),
            ]);
            return [];
        }

        return array_values(array_slice($merged, 0, $desired));
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
                'azure_words_use_max_completion_tokens' => (bool) config('services.openai.azure_words_use_max_completion_tokens', true),

                // Token controls
                'max_tokens' => (int) config('services.openai.words_max_tokens', 900),
                'max_completion_tokens' => (int) config('services.openai.words_max_completion_tokens', 700),

                // o4 / azure models may restrict temperature; your client already guards it.
                'temperature' => 0.2,

                'response_format' => $responseFormat,
            ];
        }

        return [
            'model' => (string) config('services.openai.fast_model', 'gpt-4.1-mini'),
            'max_tokens' => (int) config('services.openai.words_max_tokens', 900),
            'temperature' => 0.2,
            'response_format' => $responseFormat,
        ];
    }

    /**
     * Prompt: strongly forces correct language + canonical spelling + phrases.
     */
    protected function promptWords(string $text, string $target, string $support, int $count, int $minCount, int $chunkIndex, int $chunksTotal): string
    {
        $targetMeta = $this->langMeta($target);
        $supportMeta = $this->langMeta($support);

        $chunkLine = $chunksTotal > 0
            ? "Chunk: {$chunkIndex}/{$chunksTotal}"
            : "Chunk: full text";

        return <<<TXT
You are an expert language teacher and curriculum designer building a vocabulary pack for a language-learning app.

Return ONLY a valid JSON object. No markdown. No extra keys. No extra text.

Schema (exact):
{"words":[{"term":"","meaning":"","example_sentence":"","translation":""}]}

Goal:
Pick the most useful, high-impact vocabulary needed to understand THIS text. Prefer common, reusable expressions learners actually use.

Count:
- Return EXACTLY {$count} items.
- If truly impossible, return as many as possible but at least {$minCount}.

Hard constraints (MUST follow):
- Output MUST be valid JSON and MUST match the schema exactly.
- Every "term" MUST appear in the provided text EXACTLY as written (same casing, spaces, punctuation).
- "term" MUST be a vocabulary item (word or short phrase), NOT a full sentence or clause.

Term constraints (VERY STRICT):
- "term" word count: 1 to 5 words ONLY.
- "term" length: max 42 characters.
- "term" MUST NOT contain newline characters.
- "term" MUST NOT contain sentence punctuation: . ! ? ; :
- "term" MUST NOT contain more than 1 comma.
- "term" MUST NOT contain URLs, emails, hashtags, @handles, timestamps, raw numbers, or boilerplate UI/app text.
- Avoid proper names (people/brands/places) unless they are essential learning items.
- Prefer phrases (2–5 words) over single words when they carry meaning and appear in the text.

Meaning (STRICT):
- "meaning" must be a short learner-friendly explanation written ONLY in {$targetMeta['label']} ({$targetMeta['native']}).
- Do NOT translate word-by-word; explain the sense used in THIS text.
- Keep it concise (one short sentence or a phrase). No lists.

Example sentence (STRICT):
- "example_sentence" must be a NEW natural sentence written ONLY in {$targetMeta['label']} ({$targetMeta['native']}).
- It MUST clearly demonstrate the same meaning as "meaning".
- Keep it short, clear, realistic, and learner-friendly.
- Do NOT copy a full sentence from the text. You may reuse the term, but the sentence must be newly written.

Translation (VERY STRICT):
- "translation" must be ONLY in {$supportMeta['label']} ({$supportMeta['native']}), even inside parentheses.
- "translation" MUST translate the ENTIRE example_sentence naturally (human translation). Do NOT translate word-by-word.
- If the term is idiomatic, translate the idiomatic meaning (not the literal words).
- "translation" MUST match the exact sense used in example_sentence.
- REQUIRED FORMAT (exact structure):
  "{translated example_sentence} ({translated meaning of term})"
  The part in parentheses MUST be a short natural translation of the term itself (same sense as meaning/example).
- If you are not confident the translation is correct and natural (either sentence or term), set "translation" to "".

Quality filters:
- Avoid near-duplicates (same term with minor punctuation/plural changes).
- Avoid overly generic words (e.g., "good", "very", "thing") unless they are key in context.
- Prefer terms that are useful beyond this single text.

Spelling / teaching constraints:
- If the text explicitly teaches a correct spelling (e.g., it spells a word like "A I S L E"),
  output ONLY the correct spelling form.
- Do NOT include the common mistake form if the correct form also appears in the text.

{$chunkLine}

Text:
{$text}
TXT;
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

        // Rank: frequency first, then longer phrases (often more informative)
        $freq = [];
        foreach ($kept as $it) {
            $t = mb_strtolower((string) ($it['term'] ?? ''));
            $freq[$t] = $t !== '' ? substr_count($fullLower, $t) : 0;
        }

        usort($kept, function ($a, $b) use ($freq) {
            $ta = mb_strtolower((string) ($a['term'] ?? ''));
            $tb = mb_strtolower((string) ($b['term'] ?? ''));

            $fa = $freq[$ta] ?? 0;
            $fb = $freq[$tb] ?? 0;

            if ($fa !== $fb) return $fb <=> $fa;

            return mb_strlen((string) ($b['term'] ?? '')) <=> mb_strlen((string) ($a['term'] ?? ''));
        });

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

    protected function langMeta(string $code): array
    {
        $code = strtolower(trim($code));
        $supported = (array) config('learning_languages.supported', []);
        $meta = $supported[$code] ?? null;

        if (!is_array($meta)) {
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
