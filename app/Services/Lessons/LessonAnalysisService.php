<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\Ai\LlmClient;
use App\Services\Ai\Pipelines\ChunkedPromptRunner;
use App\Services\Text\ChunkPlan;
use App\Services\Text\ChunkPolicy;
use App\Services\Text\TextChunker;
use Illuminate\Support\Facades\Log;

class LessonAnalysisService
{
    public function __construct(
        protected LlmClient $llm,
        protected TextChunker $chunker,
        protected ChunkedPromptRunner $runner,
    ) {}

    public function generateAnalysis(Lesson $lesson, ?string $customPrompt = null): array
    {
        $raw = (string) ($lesson->original_text ?? '');
        $plainText = $this->normalizeText($raw);

        if ($plainText === '') {
            return [];
        }

        $provider = (string) config('services.openai.provider', 'openai');

        $targetLanguage = (string) ($lesson->target_language ?? config('learning_languages.default_target', 'en'));
        $supportLanguage = (string) ($lesson->support_language ?? config('learning_languages.default_support', 'en'));

        $targetMeta = $this->langMeta($targetLanguage);
        $supportMeta = $this->langMeta($supportLanguage);

        $maxChars = (int) config('services.openai.analysis_max_chars', 12000);
        $plainText = $this->shrinkText($plainText, $maxChars);

        // 1) Chunk plan for notes
        $policy = $this->analysisChunkPolicy();
        $plan = $this->chunker->plan($plainText, $policy);

        if (empty($plan->chunks)) {
            return [];
        }

        $wordCount = $this->wordCount($plainText);

        $logContext = [
            'pipeline' => 'lesson_analysis',
            'provider' => $provider,
            'lesson_id' => $lesson->id ?? null,
            'target_lang' => $targetMeta['code'],
            'support_lang' => $supportMeta['code'],
            'chunks' => count($plan->chunks),
            'word_count' => $wordCount,
            // If you later add "silent" support inside ChunkedPromptRunner, it can respect this.
            'silent' => true,
        ];

        $notesOptions = $this->llmOptionsForAnalysisNotes($provider);

        $notesResults = $this->runner->runJson(
            plan: $plan,
            messagesFactory: function (string $chunkText) use ($targetMeta, $supportMeta, $customPrompt, $wordCount) {
                return [
                    [
                        'role' => 'system',
                        'content' => 'Return ONLY a valid JSON object. No markdown. No extra text. No extra keys.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->promptAnalysisNotes(
                            text: $chunkText,
                            targetMeta: $targetMeta,
                            supportMeta: $supportMeta,
                            approxWordCount: $wordCount,
                            customPrompt: $customPrompt
                        ),
                    ],
                ];
            },
            options: $notesOptions,
            logContext: $logContext,
        );

        $notes = $this->mergeNotesFromResults($notesResults);

        if ($this->isNotesEmpty($notes)) {
            Log::warning('LessonAnalysisService: empty notes', [
                'lesson_id' => $lesson->id ?? null,
                'chunks' => count($plan->chunks),
            ]);
        }

        // 2) Final synthesis (small prompt -> stable + fast)
        $finalOptions = $this->llmOptionsForAnalysisFinal($provider);

        $finalMessages = [
            [
                'role' => 'system',
                'content' => 'Return ONLY a valid JSON object. No markdown. No extra text. Follow schema exactly.',
            ],
            [
                'role' => 'user',
                'content' => $this->promptAnalysisFinal(
                    notes: $notes,
                    targetMeta: $targetMeta,
                    supportMeta: $supportMeta,
                    approxWordCount: $wordCount,
                    customPrompt: $customPrompt
                ),
            ],
        ];

        $final = $this->llm->chatJson($finalMessages, $finalOptions);

        if (!$final->ok || !is_array($final->json)) {
            Log::warning('LessonAnalysisService: final synthesis failed', [
                'lesson_id' => $lesson->id ?? null,
                'status' => $final->status ?? null,
                'error' => $final->error ?? null,
                'content_head' => is_string($final->content) ? mb_substr($final->content, 0, 900) : null,
            ]);

            return $this->fallbackAnalysisFromNotes($notes, $supportMeta);
        }

        $normalized = $this->normalizeFinalAnalysis($final->json, $supportMeta);

        if ($normalized === null) {
            Log::warning('LessonAnalysisService: final invalid shape', [
                'lesson_id' => $lesson->id ?? null,
                'decoded_keys' => array_keys($final->json),
            ]);

            return $this->fallbackAnalysisFromNotes($notes, $supportMeta);
        }

        return $normalized;
    }

    // ------------------------------------------------------------
    // Prompts
    // ------------------------------------------------------------

    protected function supportLanguageGuard(array $supportMeta): string
    {
        $label = $supportMeta['label'];
        $native = $supportMeta['native'];

        return <<<TXT
Language guard (STRICT):
- ALL output must be written ONLY in {$label} ({$native}).
- Do NOT include words from any other language (even one word).
- If you need to mention a target-language example, you may include a very short quoted example (max 6 words) inside "quotes".
- If you are not 100% sure, write simpler sentences in {$label}.
TXT;
    }

    protected function promptAnalysisNotes(
        string $text,
        array $targetMeta,
        array $supportMeta,
        int $approxWordCount,
        ?string $customPrompt
    ): string {
        $guard = $this->supportLanguageGuard($supportMeta);

        $customBlock = '';
        if (is_string($customPrompt) && trim($customPrompt) !== '') {
            $custom = trim($customPrompt);
            $customBlock = <<<TXT

Additional user instructions (follow ONLY if they do not conflict with schema/rules):
{$custom}
TXT;
        }

        $targetLabel = $targetMeta['label'];
        $supportLabel = $supportMeta['label'];

        return <<<TXT
You will extract compact NOTES for a language-learning lesson analysis.

Lesson target language: {$targetLabel} ({$targetMeta['code']})
Learner support language: {$supportLabel} ({$supportMeta['code']})
Approx length: {$approxWordCount} words

Return ONLY JSON with this exact shape:
{
  "notes": {
    "overview_points": ["..."],
    "grammar_points": ["..."],
    "vocabulary_focus": ["..."],
    "study_tips": ["..."]
  },
  "meta_guess": {
    "estimated_level": "A1|A2|B1|B2|C1|C2 or short text",
    "estimated_time_minutes": 15
  }
}

Rules:
- Keep each bullet short (1 sentence).
- Focus only on the most useful items.
- Aim for:
  - overview_points: 2–4
  - grammar_points: 2–5
  - vocabulary_focus: 2–5
  - study_tips: 3–5 (must mention flashcards, shadowing, MCQ)
- Do not mention that you are an AI model.
- Do not add any other keys.

{$guard}
{$customBlock}

Lesson text (chunk):
{$text}
TXT;
    }

    protected function promptAnalysisFinal(
        array $notes,
        array $targetMeta,
        array $supportMeta,
        int $approxWordCount,
        ?string $customPrompt
    ): string {
        $guard = $this->supportLanguageGuard($supportMeta);

        $customBlock = '';
        if (is_string($customPrompt) && trim($customPrompt) !== '') {
            $custom = trim($customPrompt);
            $customBlock = <<<TXT

Additional user instructions (follow ONLY if they do not conflict with schema/rules):
{$custom}
TXT;
        }

        $supportLabel = $supportMeta['label'];
        $targetLabel = $targetMeta['label'];

        $notesJson = json_encode($notes, JSON_UNESCAPED_UNICODE);

        return <<<TXT
You will write the FINAL lesson analysis for a learner.

Target language of the lesson: {$targetLabel} ({$targetMeta['code']})
Support language for the learner: {$supportLabel} ({$supportMeta['code']})
Approx length: {$approxWordCount} words

You are given merged notes (from multiple chunks). Use them to produce a clean, friendly analysis.

Return ONLY JSON with this exact shape:
{
  "overview": "Short, friendly explanation in {$supportLabel}.",
  "grammar_points": "Explain only the 2–5 most useful grammar ideas. Use short paragraphs and optional bullets.",
  "vocabulary_focus": "Explain key vocabulary themes and useful words/phrases to notice.",
  "study_tips": "Concrete tips for using this lesson in the app: flashcards, shadowing, MCQ. Keep it practical.",
  "meta": {
    "estimated_level": "A1|A2|B1|B2|C1|C2 or short description in {$supportLabel}",
    "estimated_time_minutes": 15
  }
}

Rules:
- All fields must be natural, learner-friendly {$supportLabel}.
- Do NOT switch to {$targetLabel}, except very short quoted examples if needed.
- Keep tone clear, motivating, and practical.
- No extra keys. No extra text.

{$guard}
{$customBlock}

Merged notes JSON:
{$notesJson}
TXT;
    }

    // ------------------------------------------------------------
    // Options / Policies
    // ------------------------------------------------------------

    protected function llmOptionsForAnalysisNotes(string $provider): array
    {
        $responseFormat = ['type' => 'json_object'];

        if ($provider === 'azure') {
            return [
                'model' => (string) config('services.openai.azure_deployment_analysis', config('services.openai.azure_deployment_words')),
                'max_output_tokens' => (int) config('services.openai.analysis_notes_max_completion_tokens', 650),
                'temperature' => null,
                'response_format' => $responseFormat,
                'timeout' => (int) config('services.openai.analysis_timeout', 60),
                'connect_timeout' => (int) config('services.openai.analysis_connect_timeout', 10),
            ];
        }

        return [
            'model' => (string) config('services.openai.chat_model', 'gpt-4.1-mini'),
            'max_output_tokens' => (int) config('services.openai.analysis_notes_max_tokens', 800),
            'temperature' => 0.2,
            'response_format' => $responseFormat,
            'timeout' => (int) config('services.openai.analysis_timeout', 60),
            'connect_timeout' => (int) config('services.openai.analysis_connect_timeout', 10),
        ];
    }

    protected function llmOptionsForAnalysisFinal(string $provider): array
    {
        $responseFormat = ['type' => 'json_object'];

        if ($provider === 'azure') {
            return [
                'model' => (string) config('services.openai.azure_deployment_analysis', config('services.openai.azure_deployment_words')),
                'max_output_tokens' => (int) config('services.openai.analysis_final_max_completion_tokens', 900),
                'temperature' => null,
                'response_format' => $responseFormat,
                'timeout' => (int) config('services.openai.analysis_timeout', 60),
                'connect_timeout' => (int) config('services.openai.analysis_connect_timeout', 10),
            ];
        }

        return [
            'model' => (string) config('services.openai.chat_model', 'gpt-4.1-mini'),
            'max_output_tokens' => (int) config('services.openai.analysis_final_max_tokens', 1100),
            'temperature' => 0.2,
            'response_format' => $responseFormat,
            'timeout' => (int) config('services.openai.analysis_timeout', 60),
            'connect_timeout' => (int) config('services.openai.analysis_connect_timeout', 10),
        ];
    }

    protected function analysisChunkPolicy(): ChunkPolicy
    {
        if (method_exists(ChunkPolicy::class, 'forAnalysis')) {
            return ChunkPolicy::forAnalysis();
        }

        $target = (int) config('services.openai.analysis_chunk_target_words', 650);
        $overlap = (int) config('services.openai.analysis_chunk_overlap_words', 18);
        $maxChunks = (int) config('services.openai.analysis_chunk_max_chunks', 6);
        $budget = (int) config('services.openai.analysis_time_budget_ms', 65000);

        return new ChunkPolicy($target, $overlap, $maxChunks, $budget);
    }

    // ------------------------------------------------------------
    // Notes merge + final normalize
    // ------------------------------------------------------------

    protected function mergeNotesFromResults(array $results): array
    {
        $merged = [
            'overview_points' => [],
            'grammar_points' => [],
            'vocabulary_focus' => [],
            'study_tips' => [],
            'meta_guess' => [
                'estimated_level' => null,
                'estimated_time_minutes' => 15,
            ],
        ];

        $levels = [];
        $times = [];

        foreach ($results as $r) {
            $notes = data_get($r, 'json.notes');
            if (!is_array($notes)) continue;

            $merged['overview_points'] = array_merge($merged['overview_points'], $this->asStringList($notes['overview_points'] ?? null));
            $merged['grammar_points'] = array_merge($merged['grammar_points'], $this->asStringList($notes['grammar_points'] ?? null));
            $merged['vocabulary_focus'] = array_merge($merged['vocabulary_focus'], $this->asStringList($notes['vocabulary_focus'] ?? null));
            $merged['study_tips'] = array_merge($merged['study_tips'], $this->asStringList($notes['study_tips'] ?? null));

            $mg = data_get($r, 'json.meta_guess');
            if (is_array($mg)) {
                $lvl = trim((string) ($mg['estimated_level'] ?? ''));
                if ($lvl !== '') $levels[] = $lvl;

                $tm = $mg['estimated_time_minutes'] ?? null;
                if (is_numeric($tm)) $times[] = (int) $tm;
            }
        }

        $merged['overview_points'] = $this->uniqueTrimmed($merged['overview_points'], 6);
        $merged['grammar_points'] = $this->uniqueTrimmed($merged['grammar_points'], 8);
        $merged['vocabulary_focus'] = $this->uniqueTrimmed($merged['vocabulary_focus'], 8);
        $merged['study_tips'] = $this->uniqueTrimmed($merged['study_tips'], 8);

        if (!empty($levels)) {
            $merged['meta_guess']['estimated_level'] = $levels[0];
        }

        if (!empty($times)) {
            $merged['meta_guess']['estimated_time_minutes'] = $this->medianInt($times);
        }

        return $merged;
    }

    protected function isNotesEmpty(array $notes): bool
    {
        return empty($notes['overview_points'])
            && empty($notes['grammar_points'])
            && empty($notes['vocabulary_focus'])
            && empty($notes['study_tips']);
    }

    protected function normalizeFinalAnalysis(array $data, array $supportMeta): ?array
    {
        $overview = $this->cleanText($data['overview'] ?? null);
        $grammar = $this->cleanText($data['grammar_points'] ?? null);
        $vocab = $this->cleanText($data['vocabulary_focus'] ?? null);
        $tips = $this->cleanText($data['study_tips'] ?? null);
        $meta = $data['meta'] ?? null;

        if ($overview === '' || $grammar === '' || $vocab === '' || $tips === '') {
            return null;
        }

        if (!is_array($meta)) {
            $meta = [];
        }

        $level = $this->cleanText($meta['estimated_level'] ?? null);
        $minutes = $meta['estimated_time_minutes'] ?? 15;
        $minutes = is_numeric($minutes) ? (int) $minutes : 15;
        $minutes = max(5, min(90, $minutes));

        // Hard guard: if support is Persian, reject clearly Arabic-style output
        if ($supportMeta['code'] === 'fa') {
            if ($this->looksArabicTooMuch($overview . ' ' . $grammar . ' ' . $vocab . ' ' . $tips)) {
                return null;
            }
        }

        return [
            'overview' => $overview,
            'grammar_points' => $grammar,
            'vocabulary_focus' => $vocab,
            'study_tips' => $tips,
            'meta' => [
                'estimated_level' => $level !== '' ? $level : null,
                'estimated_time_minutes' => $minutes,
            ],
        ];
    }

    protected function fallbackAnalysisFromNotes(array $notes, array $supportMeta): array
    {
        $overview = $this->joinBullets($notes['overview_points'] ?? []);
        $grammar = $this->joinBullets($notes['grammar_points'] ?? []);
        $vocab = $this->joinBullets($notes['vocabulary_focus'] ?? []);
        $tips = $this->joinBullets($notes['study_tips'] ?? []);

        $metaGuess = $notes['meta_guess'] ?? [];
        $level = $this->cleanText($metaGuess['estimated_level'] ?? null);
        $minutes = $metaGuess['estimated_time_minutes'] ?? 15;
        $minutes = is_numeric($minutes) ? (int) $minutes : 15;

        $minutes = max(5, min(90, $minutes));

        // If fallback text looks bad for Persian, keep it minimal instead of wrong
        if ($supportMeta['code'] === 'fa' && $this->looksArabicTooMuch($overview . ' ' . $grammar . ' ' . $vocab . ' ' . $tips)) {
            $overview = $overview !== '' ? $overview : 'این درس درباره‌ی موضوع اصلی متن است و به فهم بهتر واژگان و ساختارها کمک می‌کند.';
            $grammar = $grammar !== '' ? $grammar : 'روی چند نکته‌ی کلیدی گرامر تمرکز کن و آن‌ها را با مثال تمرین کن.';
            $vocab = $vocab !== '' ? $vocab : 'واژه‌ها و عبارت‌های پرتکرار و مهم متن را یادداشت و مرور کن.';
            $tips = $tips !== '' ? $tips : 'با فلش‌کارت واژگان را مرور کن، با shadowing تلفظ را تمرین کن و با MCQ خودت را بسنج.';
        }

        return [
            'overview' => $overview !== '' ? $overview : null,
            'grammar_points' => $grammar !== '' ? $grammar : null,
            'vocabulary_focus' => $vocab !== '' ? $vocab : null,
            'study_tips' => $tips !== '' ? $tips : null,
            'meta' => [
                'estimated_level' => $level !== '' ? $level : null,
                'estimated_time_minutes' => $minutes,
            ],
        ];
    }

    // ------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------

    protected function normalizeText(string $text): string
    {
        $raw = strip_tags($text);
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $raw));
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

    protected function wordCount(string $text): int
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($t === '') return 0;
        $parts = preg_split('/\s+/u', $t);
        return is_array($parts) ? count($parts) : 0;
    }

    protected function asStringList($value): array
    {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $v) {
            if (!is_string($v)) continue;
            $s = trim($v);
            if ($s !== '') $out[] = $s;
        }
        return $out;
    }

    protected function uniqueTrimmed(array $items, int $limit): array
    {
        $seen = [];
        $out = [];

        foreach ($items as $it) {
            $s = trim((string) $it);
            if ($s === '') continue;

            $k = mb_strtolower(preg_replace('/\s+/u', ' ', $s) ?? $s);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;

            $out[] = $s;
            if (count($out) >= $limit) break;
        }

        return $out;
    }

    protected function medianInt(array $values): int
    {
        $vals = array_values(array_filter($values, fn($v) => is_int($v) || is_numeric($v)));
        if (empty($vals)) return 15;

        sort($vals);
        $n = count($vals);
        $mid = (int) floor($n / 2);

        if ($n % 2 === 1) return (int) $vals[$mid];

        return (int) round(((int) $vals[$mid - 1] + (int) $vals[$mid]) / 2);
    }

    protected function cleanText($value): string
    {
        if (!is_string($value)) return '';
        $t = trim($value);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    protected function joinBullets(array $items): string
    {
        $items = $this->uniqueTrimmed($items, 12);
        if (empty($items)) return '';
        return implode("\n- ", array_merge(['- ' . array_shift($items)], $items));
    }

    protected function looksArabicTooMuch(string $text): bool
    {
        $text = ' ' . $text . ' ';

        $arabicMarkers = ['ة', 'ى', 'ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ'];
        $hits = 0;

        foreach ($arabicMarkers as $m) {
            if (str_contains($text, $m)) $hits++;
        }

        $arabicWords = [' كانت ', ' كان ', ' التي ', ' الذي ', ' هذا ', ' هذه ', ' على ', ' إلى ', ' من ', ' في '];
        foreach ($arabicWords as $w) {
            if (str_contains($text, $w)) $hits++;
        }

        return $hits >= 2;
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
