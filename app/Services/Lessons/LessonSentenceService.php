<?php

namespace App\Services\Lessons;

use App\Services\Ai\LlmClient;
use App\Services\Ai\Pipelines\ChunkedPromptRunner;
use App\Services\Text\ChunkPolicy;
use App\Services\Text\ChunkPlan;
use App\Services\Text\TextChunker;
use Illuminate\Support\Facades\Log;

class LessonSentenceService
{
    public function __construct(
        protected LlmClient $llm,
        protected TextChunker $chunker,
        protected ChunkedPromptRunner $runner,
    ) {}

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

        $rawText = (string) $text;
        $text = $this->normalizeText($text);

        if ($text === '') {
            return [];
        }

        $minItems = (int) config('services.openai.shadowing_min_items', 12);
        $maxItems = (int) config('services.openai.shadowing_max_items', 20);

        // Decide desired count by text size but clamp to min/max
        $desired = $this->suggestSentenceCount($text, $minItems, $maxItems);

        // Shrink before chunking (keeps head+tail)
        $maxChars = (int) config('services.openai.shadowing_max_chars', 7000);
        $shrunk = $this->shrinkText($text, $maxChars);

        $policy = $this->sentenceChunkPolicy();
        $plan = $this->chunker->plan($shrunk, $policy);

        if (empty($plan->chunks)) {
            return [];
        }

        $perChunkTarget = $this->planPerChunkCount(count($plan->chunks), $desired);
        $perChunkMin = max(3, (int) floor($perChunkTarget * 0.6));

        $options = $this->llmOptionsForSentences($provider);

        $logContext = [
            'pipeline' => 'lesson_sentences',
            'provider' => $provider,
            'desired' => $desired,
            'min_items' => $minItems,
            'max_items' => $maxItems,
            'chunks' => count($plan->chunks),
            'per_chunk_target' => $perChunkTarget,
            'per_chunk_min' => $perChunkMin,
            'total_words' => $plan->totalWords ?? null,
            'total_chars' => $plan->totalChars ?? null,
            'target_words' => $plan->targetWords ?? null,
            'overlap_words' => $plan->overlapWords ?? null,
            'time_budget_ms' => $policy->timeBudgetMs ?? null,
            'target_lang' => $targetLanguage,
            'support_lang' => $supportLanguage,
        ];

        $all = [];
        $merged = [];

        // Run per chunk, and stop early once we have enough.
        foreach ($plan->chunks as $i => $chunkText) {
            $chunkIndex = $i + 1;
            $chunksTotal = count($plan->chunks);

            $singlePlan = new ChunkPlan(
                [$chunkText],
                (int) ($plan->targetWords ?? 0),
                (int) ($plan->overlapWords ?? 0),
                $this->wordCount($chunkText),
                mb_strlen($chunkText),
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

            foreach ($results as $r) {
                $items = data_get($r, 'json.sentences');
                if (!is_array($items)) continue;
                foreach ($items as $it) {
                    if (is_array($it)) $all[] = $it;
                }
            }

            $merged = $this->mergeAndCleanSentences(
                items: $all,
                target: $targetLanguage,
                support: $supportLanguage,
                fullText: $rawText,
                maxKeep: $desired,
            );

            if (count($merged) >= $desired) {
                break;
            }
        }

        // Fallback: run once on full text if still too few
        if (count($merged) < min($minItems, $desired)) {
            $fallbackPlan = new ChunkPlan(
                [$shrunk],
                0,
                0,
                $this->wordCount($shrunk),
                mb_strlen($shrunk),
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
                            'content' => $this->promptSentences(
                                text: $t,
                                target: $targetLanguage,
                                support: $supportLanguage,
                                count: $desired,
                                minCount: min($minItems, $desired),
                                chunkIndex: 0,
                                chunksTotal: 0,
                            ),
                        ],
                    ];
                },
                options: $options,
                logContext: $logContext + ['chunk' => 0, 'chunks_total' => 0, 'fallback' => true],
            );

            $more = [];
            foreach ($fallbackResults as $r) {
                $items = data_get($r, 'json.sentences');
                if (!is_array($items)) continue;
                foreach ($items as $it) {
                    if (is_array($it)) $more[] = $it;
                }
            }

            $merged = $this->mergeAndCleanSentences(
                items: array_merge($merged, $more),
                target: $targetLanguage,
                support: $supportLanguage,
                fullText: $rawText,
                maxKeep: $desired,
            );
        }

        if (count($merged) < min($minItems, $desired)) {
            Log::warning('LessonSentenceService: insufficient sentences', $logContext + [
                    'items' => count($merged),
                    'need_at_least' => min($minItems, $desired),
                ]);
            return [];
        }

        return array_values(array_slice($merged, 0, $desired));
    }

    // ---------------------------
    // LLM options / chunk policy
    // ---------------------------

    protected function llmOptionsForSentences(string $provider): array
    {
        // IMPORTANT: response_format must be an object
        $responseFormat = ['type' => 'json_object'];

        if ($provider === 'azure') {
            return [
                // Your LlmClient already reads provider from config, but we pass these for pipeline consistency
                'azure_deployment' => (string) config('services.openai.azure_deployment', config('services.openai.azure_deployment_words')),
                'azure_api_version' => (string) config('services.openai.azure_api_version'),
                'azure_use_v1' => (bool) config('services.openai.azure_use_v1', true),

                // Use a single unified key that your OpenAiLlmClient actually reads:
                'max_output_tokens' => (int) config('services.openai.shadowing_max_completion_tokens', 900),

                // Don't force temperature for o*/Azure (some models reject non-default)
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
        // If you added ChunkPolicy::forSentences() use it, otherwise fallback to config-driven constructor-like policy.
        if (method_exists(ChunkPolicy::class, 'forSentences')) {
            return ChunkPolicy::forSentences();
        }

        // Fallback: reuse shared chunk settings (you can override with shadowing_* keys later)
        $target = (int) config('services.openai.shadowing_chunk_target_words', config('services.openai.words_chunk_target_words', 450));
        $overlap = (int) config('services.openai.shadowing_chunk_overlap_words', config('services.openai.words_chunk_overlap_words', 12));
        $maxChunks = (int) config('services.openai.shadowing_chunk_max_chunks', config('services.openai.words_chunk_max_chunks', 6));
        $budget = (int) config('services.openai.shadowing_time_budget_ms', config('services.openai.words_time_budget_ms', 55000));

        // Assumes your ChunkPolicy has public properties + constructor in this order:
        // new ChunkPolicy(targetWords, overlapWords, maxChunks, timeBudgetMs)
        return new ChunkPolicy($target, $overlap, $maxChunks, $budget);
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
- Do NOT include any words, letters, or phrases from any other language (even one word).
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
        $supportMeta = $this->langMeta($support);
        $guard = $this->translationLanguageGuard($support);

        $chunkLine = $chunksTotal > 0
            ? "Chunk: {$chunkIndex}/{$chunksTotal}"
            : "Chunk: full text";

        return <<<TXT
You are creating shadowing-ready sentences for a language-learning app.

Return ONLY valid JSON. No markdown. No extra keys. No extra text.

Schema (exact):
{"sentences":[{"text":"","translation":""}]}

Goal:
Select natural, content-rich sentences that learners can repeat aloud.
Avoid noise.

Rules for selecting sentences:
- Use ONLY the lesson content. Exclude intros/outros, greetings, calls-to-action, platform/channel talk, URLs, timestamps, and filler.
- Prefer clear, everyday language with strong meaning (actions, feelings, events, causes).
- Each sentence should be 5–16 words when possible. Do NOT exceed 22 words.
- If a sentence is too long, you may split it into shorter natural sentences while preserving meaning.
- Do NOT output fragments, broken lines, or messy punctuation.
- Avoid duplicates / near-duplicates.

Count:
- Return EXACTLY {$count} sentences.
- If truly impossible, return as many as possible but at least {$minCount}.

Language constraints:
- "text" must be written ONLY in {$targetMeta['label']} ({$targetMeta['native']}).
{$guard}

Important:
- "translation" must translate the sentence text (not a summary).
- No transliteration.

{$chunkLine}

Text:
{$text}
TXT;
    }

    // ---------------------------
    // Cleaning / merging
    // ---------------------------

    protected function mergeAndCleanSentences(array $items, string $target, string $support, string $fullText, int $maxKeep): array
    {
        $out = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $text = trim((string) ($item['text'] ?? $item['sentence'] ?? ''));
            $translation = trim((string) ($item['translation'] ?? ''));

            if ($text === '') continue;

            // Basic cleanup
            $text = $this->cleanSentenceText($text);
            if ($text === '') continue;

            // Drop obvious junk/filler lines
            if ($this->looksLikeJunk($text)) continue;

            // Length guard (soft)
            if (!$this->sentenceLengthOk($text)) continue;

            // Translation language guard (soft-but-strict)
            if ($translation !== '') {
                $translation = $this->cleanSentenceText($translation);

                if (!$this->translationLooksValidForSupport($translation, $support)) {
                    $translation = '';
                }
            }

            $key = $this->sentenceKey($text);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $out[] = [
                'text' => $text,
                'translation' => $translation !== '' ? $translation : null,
            ];

            if (count($out) >= $maxKeep) break;
        }

        return $out;
    }

    protected function cleanSentenceText(string $s): string
    {
        $s = trim($s);

        // Remove common wrappers
        $s = preg_replace('/^\[music\]\s*/iu', '', $s) ?? $s;
        $s = preg_replace('/\s*\[music\]$/iu', '', $s) ?? $s;

        // Normalize whitespace
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        // Strip leading/trailing quotes
        $s = trim($s, " \t\n\r\0\x0B\"“”‘’'«»");

        return trim($s);
    }

    protected function looksLikeJunk(string $text): bool
    {
        $t = mb_strtolower($text);

        // platform / CTA / meta talk
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
            'bye',
            'vocabulary preview',
            'language takeaway',
            'slow down',
        ];

        foreach ($bad as $b) {
            if (str_contains($t, $b)) return true;
        }

        // too much punctuation or ellipsis
        if (preg_match('/\.{3,}/u', $text)) return true;

        // timestamps / urls
        if (preg_match('/https?:\/\/\S+/iu', $text)) return true;
        if (preg_match('/\b\d{1,2}:\d{2}\b/u', $text)) return true;

        return false;
    }

    protected function sentenceLengthOk(string $text): bool
    {
        $len = mb_strlen($text);
        if ($len < 12) return false;
        if ($len > 220) return false;

        // Word-count heuristic (only for spaced languages)
        $wc = $this->wordCount($text);
        if ($wc >= 2) {
            if ($wc < 5) return false;
            if ($wc > 22) return false;
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
        if ($translation === '') return false;

        $code = strtolower(trim($supportCode));

        // Generic: if support is NOT Arabic-script, forbid Arabic-script letters (helps prevent Arabic leakage)
        if (!in_array($code, ['fa', 'ar', 'ur'], true)) {
            if (preg_match('/\p{Arabic}/u', $translation)) {
                return false;
            }
        }

        // Special: Persian guard against Arabic sentences (heuristic, not perfect)
        if ($code === 'fa') {
            // Arabic-only markers and common Arabic words
            $arabicMarkers = ['ة', 'ى', 'ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ'];
            $hits = 0;

            foreach ($arabicMarkers as $m) {
                if (str_contains($translation, $m)) $hits++;
            }

            // Common Arabic function words (sentence-like Arabic)
            $arabicWords = [' كانت ', ' كان ', ' التي ', ' الذي ', ' هذا ', ' هذه ', ' على ', ' إلى ', ' من ', ' في ', ' و '];
            foreach ($arabicWords as $w) {
                if (str_contains(' ' . $translation . ' ', $w)) $hits++;
            }

            // If it looks strongly Arabic, reject
            if ($hits >= 2) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------
    // Helpers
    // ---------------------------

    protected function suggestSentenceCount(string $text, int $min, int $max): int
    {
        $n = $this->wordCount($text);

        $desired = 16;
        if ($n <= 250) $desired = 12;
        elseif ($n <= 500) $desired = 14;
        elseif ($n <= 900) $desired = 16;
        else $desired = 18;

        return max($min, min($max, $desired));
    }

    protected function planPerChunkCount(int $chunks, int $desiredTotal): int
    {
        if ($chunks <= 1) return $desiredTotal;

        $base = (int) ceil($desiredTotal / $chunks);

        $minPer = (int) config('services.openai.shadowing_min_per_chunk', 4);
        $maxPer = (int) config('services.openai.shadowing_max_per_chunk', 8);

        return max($minPer, min($maxPer, $base));
    }

    protected function normalizeText(string $text): string
    {
        $t = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));
        return $t;
    }

    protected function wordCount(string $text): int
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($t === '') return 0;
        $parts = preg_split('/\s+/u', $t);
        return is_array($parts) ? count($parts) : 0;
    }

    protected function shrinkText(string $text, int $maxChars): string
    {
        $t = $this->normalizeText($text);

        if ($maxChars <= 0) return $t;
        if (mb_strlen($t) <= $maxChars) return $t;

        $half = (int) floor($maxChars / 2);
        $head = mb_substr($t, 0, $half);
        $tail = mb_substr($t, -$half);

        return $head . "\n...\n" . $tail;
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
