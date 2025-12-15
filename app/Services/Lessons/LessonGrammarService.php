<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\Ai\LlmClient;
use App\Services\Ai\Pipelines\ChunkedPromptRunner;
use App\Services\Text\ChunkPlan;
use App\Services\Text\ChunkPolicy;
use App\Services\Text\TextChunker;
use Illuminate\Support\Facades\Log;

class LessonGrammarService
{
    public function __construct(
        protected LlmClient $llm,
        protected TextChunker $chunker,
        protected ChunkedPromptRunner $runner,
    ) {}

    public function generateGrammar(Lesson $lesson, ?string $customPrompt = null): array
    {
        if (! $lesson->original_text) {
            return [
                'grammar_points' => [],
                'exercises' => [],
            ];
        }

        $provider = (string) config('services.openai.provider', 'openai');

        $targetLanguage = (string) ($lesson->target_language ?? config('learning_languages.default_target', 'en'));
        $supportLanguage = (string) ($lesson->support_language ?? config('learning_languages.default_support', 'en'));

        $targetMeta = $this->langMeta($targetLanguage);
        $supportMeta = $this->langMeta($supportLanguage);

        $plainText = $this->normalizeText($lesson->original_text);

        if ($plainText === '') {
            return [
                'grammar_points' => [],
                'exercises' => [],
            ];
        }

        $wordCount = $this->wordCount($plainText);

        $maxChars = (int) config('services.openai.grammar_max_chars', 5000);
        $shrunk = $this->shrinkText($plainText, $maxChars);

        // Decide: 1 or 2 grammar points total
        $desiredTotal = $this->desiredGrammarPointCount($wordCount);

        $policy = $this->grammarChunkPolicy();
        $plan = $this->chunker->plan($shrunk, $policy);

        if (empty($plan->chunks)) {
            return [
                'grammar_points' => [],
                'exercises' => [],
            ];
        }

        $options = $this->llmOptionsForGrammar($provider);

        $logContext = [
            'pipeline' => 'lesson_grammar',
            'provider' => $provider,
            'lesson_id' => $lesson->id ?? null,
            'target_lang' => $targetLanguage,
            'support_lang' => $supportLanguage,
            'desired' => $desiredTotal,
            'chunks' => count($plan->chunks),
            'total_words' => $plan->totalWords ?? null,
            'total_chars' => $plan->totalChars ?? null,
            'time_budget_ms' => $policy->timeBudgetMs ?? null,
        ];

        // Strategy: request only 1 point per chunk => faster + stable
        $perChunkTarget = 1;
        $perChunkMin = 1;

        $all = [];

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
                messagesFactory: function (string $t) use ($targetMeta, $supportMeta, $customPrompt, $perChunkTarget, $perChunkMin, $chunkIndex, $chunksTotal) {
                    return [
                        [
                            'role' => 'system',
                            'content' => 'You extract compact grammar points for language learners. Return strict JSON only.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->promptGrammar(
                                text: $t,
                                targetMeta: $targetMeta,
                                supportMeta: $supportMeta,
                                count: $perChunkTarget,
                                minCount: $perChunkMin,
                                chunkIndex: $chunkIndex,
                                chunksTotal: $chunksTotal,
                                customPrompt: $customPrompt,
                            ),
                        ],
                    ];
                },
                options: $options,
                logContext: $logContext + ['chunk' => $chunkIndex, 'chunks_total' => $chunksTotal],
            );

            foreach ($results as $r) {
                $points = data_get($r, 'json.grammar_points');
                if (!is_array($points)) continue;
                foreach ($points as $p) {
                    if (is_array($p)) $all[] = $p;
                }
            }

            $merged = $this->normalizeGrammarPoints($all, $targetMeta, $supportMeta);

            if (count($merged) >= $desiredTotal) {
                return [
                    'grammar_points' => array_slice($merged, 0, $desiredTotal),
                    'exercises' => [],
                ];
            }
        }

        // Fallback: run once on full shrunk text requesting desiredTotal
        $fallbackPlan = new ChunkPlan(
            [$shrunk],
            0,
            0,
            $this->wordCount($shrunk),
            mb_strlen($shrunk),
        );

        $fallbackResults = $this->runner->runJson(
            plan: $fallbackPlan,
            messagesFactory: function (string $t) use ($targetMeta, $supportMeta, $customPrompt, $desiredTotal) {
                return [
                    [
                        'role' => 'system',
                        'content' => 'You extract compact grammar points for language learners. Return strict JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->promptGrammar(
                            text: $t,
                            targetMeta: $targetMeta,
                            supportMeta: $supportMeta,
                            count: $desiredTotal,
                            minCount: 1,
                            chunkIndex: 0,
                            chunksTotal: 0,
                            customPrompt: $customPrompt,
                        ),
                    ],
                ];
            },
            options: $options,
            logContext: $logContext + ['chunk' => 0, 'chunks_total' => 0, 'fallback' => true],
        );

        $more = [];
        foreach ($fallbackResults as $r) {
            $points = data_get($r, 'json.grammar_points');
            if (!is_array($points)) continue;
            foreach ($points as $p) {
                if (is_array($p)) $more[] = $p;
            }
        }

        $final = $this->normalizeGrammarPoints(array_merge($all, $more), $targetMeta, $supportMeta);

        if (count($final) < 1) {
            Log::warning('LessonGrammarService: empty/invalid grammar output', $logContext);
            return [
                'grammar_points' => [],
                'exercises' => [],
            ];
        }

        return [
            'grammar_points' => array_slice($final, 0, $desiredTotal),
            'exercises' => [],
        ];
    }

    // ---------------------------
    // Prompt
    // ---------------------------

    protected function translationLanguageGuard(array $supportMeta): string
    {
        $label = $supportMeta['label'];
        $native = $supportMeta['native'];

        return <<<TXT
Language guard (STRICT):
- All support-language fields MUST be written ONLY in {$label} ({$native}).
- Support-language fields are: title, description, examples[].translation.
- Do NOT include any words from other languages (even one word).
- If you are not 100% sure, use an empty string for that field.
TXT;
    }

    protected function promptGrammar(
        string $text,
        array $targetMeta,
        array $supportMeta,
        int $count,
        int $minCount,
        int $chunkIndex,
        int $chunksTotal,
        ?string $customPrompt
    ): string {
        $targetLabel = $targetMeta['label'];
        $supportLabel = $supportMeta['label'];
        $targetCode = $targetMeta['code'];
        $supportCode = $supportMeta['code'];

        $wc = $this->wordCount($text);

        $chunkLine = $chunksTotal > 0
            ? "Chunk: {$chunkIndex}/{$chunksTotal}"
            : "Chunk: full text";

        $customBlock = '';
        if (is_string($customPrompt) && trim($customPrompt) !== '') {
            $custom = trim($customPrompt);
            $customBlock = <<<TXT

Additional user preferences (follow ONLY if they do not conflict with schema/rules):
{$custom}
TXT;
        }

        $guard = $this->translationLanguageGuard($supportMeta);

        return <<<TXT
Extract ONLY the most important grammar points from this {$targetLabel} lesson in a compact, UI-friendly way.

Lesson language: {$targetLabel} ({$targetCode})
Learner language: {$supportLabel} ({$supportCode})
Approx length: {$wc} words

Rules:
- Return ONLY JSON. No extra text. No markdown.
- Choose EXACTLY {$count} grammar point(s). If truly impossible, return as many as possible but at least {$minCount}.
- Keep it simple: speak like a friendly tutor in {$supportLabel}.
- Each grammar point must include:
  1) WHEN we use it (real-life)
  2) HOW we build it (structure)
  3) ONE common mistake
- Keep description short (max 3 short sentences).
- Provide EXACTLY 2 examples:
  - One close to the lesson (source="lesson")
  - One simple extra example (source="extra")
- Examples: sentence in {$targetLabel}, translation in {$supportLabel}.
- NO exercises at all.

{$guard}

Return JSON with this exact shape:

{
  "grammar_points": [
    {
      "id": "short_key",
      "title": "Short title in {$supportLabel}",
      "level": "A1|A2|B1|B2|C1|C2 or null",
      "description": "Short explanation in {$supportLabel}. Include when/how/mistake.",
      "pattern": "One short pattern line in {$targetLabel} notation.",
      "examples": [
        {
          "sentence": "Sentence in {$targetLabel}",
          "translation": "Natural {$supportLabel}",
          "source": "lesson|extra"
        }
      ],
      "meta": {
        "importance": "high|medium|low",
        "tags": ["tag_one", "tag_two"]
      }
    }
  ],
  "exercises": []
}

{$customBlock}

{$chunkLine}

Lesson text:
{$text}
TXT;
    }

    // ---------------------------
    // Options / policies
    // ---------------------------

    protected function llmOptionsForGrammar(string $provider): array
    {
        $responseFormat = ['type' => 'json_object'];

        if ($provider === 'azure') {
            return [
                // IMPORTANT: in Azure v1, "model" = deployment name
                'model' => (string) config('services.openai.azure_deployment_grammar', config('services.openai.azure_deployment_words')),
                'azure_use_v1' => (bool) config('services.openai.azure_use_v1', true),
                'azure_api_version' => (string) config('services.openai.azure_api_version'),

                // One unified key that your OpenAiLlmClient consumes:
                'max_output_tokens' => (int) config('services.openai.grammar_max_completion_tokens', 900),

                // Avoid non-default temp for Azure/o* models
                'temperature' => null,

                'response_format' => $responseFormat,
            ];
        }

        return [
            'model' => (string) config('services.openai.chat_model', 'gpt-4.1-mini'),
            'max_output_tokens' => (int) config('services.openai.grammar_max_tokens', 900),
            'temperature' => 0.2,
            'response_format' => $responseFormat,
        ];
    }

    protected function grammarChunkPolicy(): ChunkPolicy
    {
        if (method_exists(ChunkPolicy::class, 'forGrammar')) {
            return ChunkPolicy::forGrammar();
        }

        $target = (int) config('services.openai.grammar_chunk_target_words', config('services.openai.words_chunk_target_words', 450));
        $overlap = (int) config('services.openai.grammar_chunk_overlap_words', config('services.openai.words_chunk_overlap_words', 12));
        $maxChunks = (int) config('services.openai.grammar_chunk_max_chunks', config('services.openai.words_chunk_max_chunks', 6));
        $budget = (int) config('services.openai.grammar_time_budget_ms', config('services.openai.words_time_budget_ms', 55000));

        return new ChunkPolicy($target, $overlap, $maxChunks, $budget);
    }

    protected function desiredGrammarPointCount(int $wordCount): int
    {
        // Rule you wanted: 1 or 2 (prefer 1 if short/simple)
        if ($wordCount <= 350) return 1;
        return 2;
    }

    // ---------------------------
    // Normalization / validation
    // ---------------------------

    protected function normalizeGrammarPoints(array $points, array $targetMeta, array $supportMeta): array
    {
        $out = [];
        $seen = [];

        foreach ($points as $p) {
            if (!is_array($p)) continue;

            $id = trim((string) ($p['id'] ?? ''));
            $title = trim((string) ($p['title'] ?? ''));
            $description = trim((string) ($p['description'] ?? ''));
            $pattern = trim((string) ($p['pattern'] ?? ''));

            if ($id === '' || $title === '' || $description === '' || $pattern === '') {
                continue;
            }

            // Support-language guard (heuristic). If it fails badly, drop point.
            if (!$this->supportTextLooksValid($title, $supportMeta['code']) || !$this->supportTextLooksValid($description, $supportMeta['code'])) {
                continue;
            }

            $key = $this->normalizeGrammarIdKey($id);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $examples = $p['examples'] ?? [];
            if (!is_array($examples)) $examples = [];

            $examplesOut = [];
            foreach ($examples as $ex) {
                if (!is_array($ex)) continue;

                $sentence = trim((string) ($ex['sentence'] ?? ''));
                $translation = trim((string) ($ex['translation'] ?? ''));
                $source = (string) ($ex['source'] ?? 'extra');

                if ($sentence === '') continue;

                // Translation is required by schema; if invalid language, drop the example
                if ($translation === '' || !$this->supportTextLooksValid($translation, $supportMeta['code'])) {
                    continue;
                }

                if ($source !== 'lesson' && $source !== 'extra') {
                    $source = 'extra';
                }

                $examplesOut[] = [
                    'sentence' => $sentence,
                    'translation' => $translation,
                    'source' => $source,
                ];
            }

            // Must have exactly 2 examples (lesson + extra ideally)
            if (count($examplesOut) < 2) {
                continue;
            }

            // Keep at most 2, and try to prioritize lesson+extra mix
            $examplesOut = $this->pickBestTwoExamples($examplesOut);

            $level = $p['level'] ?? null;
            if (!is_string($level) || trim($level) === '') $level = null;

            $meta = $p['meta'] ?? null;
            if (!is_array($meta)) $meta = null;

            $out[] = [
                'id' => $id,
                'title' => $title,
                'level' => $level,
                'description' => $description,
                'pattern' => $pattern,
                'examples' => $examplesOut,
                'meta' => $meta,
            ];
        }

        return array_slice($out, 0, 2);
    }

    protected function pickBestTwoExamples(array $examples): array
    {
        $lesson = null;
        $extra = null;

        foreach ($examples as $ex) {
            if (($ex['source'] ?? '') === 'lesson' && $lesson === null) $lesson = $ex;
            if (($ex['source'] ?? '') === 'extra' && $extra === null) $extra = $ex;
        }

        if ($lesson && $extra) return [$lesson, $extra];

        return array_slice($examples, 0, 2);
    }

    protected function normalizeGrammarIdKey(string $id): string
    {
        $t = mb_strtolower(trim($id));
        $t = preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $t) ?? $t;
        $t = preg_replace('/\s+/u', '_', $t) ?? $t;
        return trim($t);
    }

    protected function supportTextLooksValid(string $text, string $supportCode): bool
    {
        $text = trim($text);
        if ($text === '') return false;

        $code = strtolower(trim($supportCode));

        // If support is NOT Arabic-script, forbid Arabic script
        if (!in_array($code, ['fa', 'ar', 'ur'], true)) {
            if (preg_match('/\p{Arabic}/u', $text)) return false;
        }

        // For Persian: try to reject strongly Arabic-looking sentences
        if ($code === 'fa') {
            $arabicMarkers = ['ة', 'ى', 'ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ'];
            $hits = 0;

            foreach ($arabicMarkers as $m) {
                if (str_contains($text, $m)) $hits++;
            }

            $arabicWords = [' كانت ', ' كان ', ' التي ', ' الذي ', ' هذا ', ' هذه ', ' على ', ' إلى ', ' من ', ' في ', ' و '];
            foreach ($arabicWords as $w) {
                if (str_contains(' ' . $text . ' ', $w)) $hits++;
            }

            if ($hits >= 2) return false;
        }

        return true;
    }

    // ---------------------------
    // Text helpers
    // ---------------------------

    protected function normalizeText(string $htmlOrText): string
    {
        $t = strip_tags($htmlOrText);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));

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
