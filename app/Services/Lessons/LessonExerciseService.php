<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Services\Ai\Pipelines\ChunkedPromptRunner;
use App\Services\Text\ChunkPlan;
use App\Services\Text\ChunkPolicy;
use App\Services\Text\TextChunker;
use Illuminate\Support\Facades\Log;

class LessonExerciseService
{
    public function __construct(
        protected TextChunker $chunker,
        protected ChunkedPromptRunner $runner,
    ) {}

    public function generate(
        Lesson $lesson,
        array $words = [],
        array $grammarPoints = [],
        int $count = 18,
        ?string $customPrompt = null
    ): array {
        $plainText = $this->normalizeText((string) ($lesson->original_text ?? ''));
        if ($plainText === '') {
            return [];
        }

        $provider = (string) config('services.openai.provider', 'openai');

        $targetLanguage = (string) ($lesson->target_language ?? config('learning_languages.default_target', 'en'));
        $supportLanguage = (string) ($lesson->support_language ?? config('learning_languages.default_support', 'en'));

        $targetMeta = $this->langMeta($targetLanguage);
        $supportMeta = $this->langMeta($supportLanguage);

        $count = max(8, min(24, (int) $count));

        $wordsCompact = $this->compactWords($words, 12);
        $grammarCompact = $this->compactGrammar($grammarPoints, 4);

        $anchors = $this->buildAnchors($plainText, $wordsCompact, 18);
        $effectiveCount = $this->effectiveCount($plainText, $count, $anchors);

        $minCount = max(6, min($effectiveCount, $effectiveCount - 1));

        $vocabMin = max(2, (int) floor($effectiveCount * 0.4));
        $grammarMin = max(2, (int) floor($effectiveCount * 0.35));
        if ($vocabMin + $grammarMin > $effectiveCount) {
            $vocabMin = (int) floor($effectiveCount / 2);
            $grammarMin = $effectiveCount - $vocabMin;
        }

        $policy = ChunkPolicy::forExercises();
        $planText = $this->shrinkText($plainText, (int) config('services.openai.exercises_max_chars', 6500));
        $plan = $this->chunker->plan($planText, $policy);

        if (empty($plan->chunks)) {
            return [];
        }

        $options = $this->llmOptionsForExercises($provider);

        $logContext = [
            'pipeline' => 'lesson_exercises',
            'provider' => $provider,
            'lesson_id' => $lesson->id ?? null,
            'target_lang' => $targetMeta['code'],
            'support_lang' => $supportMeta['code'],
            'count' => $effectiveCount,
            'min_count' => $minCount,
            'chunks' => count($plan->chunks),
            'time_budget_ms' => $policy->timeBudgetMs,
        ];

        $batch = $this->batchSize($effectiveCount, $provider);
        $maxRounds = max(3, (int) ceil($effectiveCount / max(1, $batch)) + 4);

        $raw = [];

        for ($round = 1; $round <= $maxRounds; $round++) {
            $normalized = $this->dedupeExercises($this->normalizeExercises($raw, $targetMeta, $supportMeta, $anchors));
            $selected = $this->selectBalanced($normalized, $effectiveCount, $vocabMin, $grammarMin, $anchors);

            if (count($selected) >= $effectiveCount) {
                return array_slice($selected, 0, $effectiveCount);
            }

            $need = $effectiveCount - count($selected);
            $need = max(3, min($batch, $need));

            $needVocab = max(0, $vocabMin - $this->countSkill($selected, 'vocabulary'));
            $needGrammar = max(0, $grammarMin - $this->countSkill($selected, 'grammar'));
            if ($needVocab + $needGrammar > $need) {
                $needVocab = (int) floor($need / 2);
                $needGrammar = $need - $needVocab;
            }

            $chunkText = $plan->chunks[($round - 1) % max(1, count($plan->chunks))];
            $chunkText = $this->shrinkText($chunkText, (int) config('services.openai.exercises_prompt_text_max_chars', 3200));

            $singlePlan = new ChunkPlan(
                [$chunkText],
                (int) ($plan->targetWords ?? 0),
                (int) ($plan->overlapWords ?? 0),
                $this->wordCount($chunkText),
                mb_strlen($chunkText),
            );

            $results = $this->runner->runJson(
                plan: $singlePlan,
                messagesFactory: function (string $t) use (
                    $targetMeta,
                    $supportMeta,
                    $wordsCompact,
                    $grammarCompact,
                    $anchors,
                    $need,
                    $needVocab,
                    $needGrammar,
                    $customPrompt,
                    $selected
                ) {
                    return [
                        ['role' => 'system', 'content' => 'Return ONLY valid JSON. No markdown. No extra text.'],
                        ['role' => 'user', 'content' => $this->promptExercisesBatch(
                            text: $t,
                            targetMeta: $targetMeta,
                            supportMeta: $supportMeta,
                            words: $wordsCompact,
                            grammarPoints: $grammarCompact,
                            anchors: $anchors,
                            alreadyHave: $this->compactExistingExercises($selected, 18),
                            count: $need,
                            vocabMin: $needVocab,
                            grammarMin: $needGrammar,
                            customPrompt: $customPrompt
                        )],
                    ];
                },
                options: $options,
                logContext: $logContext + ['round' => $round],
            );

            $added = 0;

            foreach ($results as $r) {
                $items = data_get($r, 'json.exercises');
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $ex) {
                    if (is_array($ex)) {
                        $raw[] = $ex;
                        $added++;
                    }
                }
            }

            if ($added === 0) {
                break;
            }
        }

        $normalized = $this->dedupeExercises($this->normalizeExercises($raw, $targetMeta, $supportMeta, $anchors));
        $balanced = $this->selectBalanced($normalized, $effectiveCount, $vocabMin, $grammarMin, $anchors);

        if (count($balanced) < $minCount) {
            Log::warning('LessonExerciseService: insufficient exercises', [
                'lesson_id' => $lesson->id ?? null,
                'got' => count($balanced),
                'need_min' => $minCount,
                'target' => $effectiveCount,
            ]);
        }

        return array_slice($balanced, 0, $effectiveCount);
    }

    protected function effectiveCount(string $text, int $requested, array $anchors): int
    {
        $wc = $this->wordCount($text);
        $a = count($anchors);

        if ($wc <= 140) {
            $cap = max(8, min(14, $a + 6));
            return min($requested, $cap);
        }

        if ($wc <= 240) {
            $cap = max(10, min(18, $a + 7));
            return min($requested, $cap);
        }

        return $requested;
    }

    protected function batchSize(int $count, string $provider): int
    {
        $default = $provider === 'azure' ? 5 : 6;
        $v = (int) config('services.openai.exercises_batch_size', $default);
        $v = max(3, min(6, $v));
        return min($v, $count);
    }

    protected function llmOptionsForExercises(string $provider): array
    {
        $responseFormat = ['type' => 'json_object'];

        if ($provider === 'azure') {
            return [
                'model' => (string) config('services.openai.azure_deployment_exercises', config('services.openai.azure_deployment_words')),
                'max_output_tokens' => (int) config('services.openai.exercises_max_completion_tokens', 900),
                'temperature' => 0.2,
                'response_format' => $responseFormat,
                'timeout' => (int) config('services.openai.exercises_timeout', 60),
                'connect_timeout' => (int) config('services.openai.exercises_connect_timeout', 10),
            ];
        }

        return [
            'model' => (string) config('services.openai.chat_model', 'gpt-4.1-mini'),
            'max_output_tokens' => (int) config('services.openai.exercises_max_tokens', 1200),
            'temperature' => 0.2,
            'response_format' => $responseFormat,
            'timeout' => (int) config('services.openai.exercises_timeout', 60),
            'connect_timeout' => (int) config('services.openai.exercises_connect_timeout', 10),
        ];
    }

    protected function translationLanguageGuard(array $supportMeta): string
    {
        $label = $supportMeta['label'];
        $native = $supportMeta['native'];

        return <<<TXT
Language guard (STRICT):
- Support-language fields MUST be ONLY {$label} ({$native}).
- Support fields: instructions, solution_explanation, options[].explanation.
- If unsure, output empty string "".
TXT;
    }

    protected function promptExercisesBatch(
        string $text,
        array $targetMeta,
        array $supportMeta,
        array $words,
        array $grammarPoints,
        array $anchors,
        array $alreadyHave,
        int $count,
        int $vocabMin,
        int $grammarMin,
        ?string $customPrompt
    ): string {
        $targetLabel = $targetMeta['label'];
        $supportLabel = $supportMeta['label'];

        $guard = $this->translationLanguageGuard($supportMeta);

        $customBlock = '';
        if (is_string($customPrompt) && trim($customPrompt) !== '') {
            $customBlock = "\n\nUser instructions:\n" . trim($customPrompt) . "\n";
        }

        $anchorsBlock = json_encode(array_values($anchors), JSON_UNESCAPED_UNICODE);
        $wordsBlock = json_encode($words, JSON_UNESCAPED_UNICODE);
        $grammarBlock = json_encode($grammarPoints, JSON_UNESCAPED_UNICODE);
        $haveBlock = json_encode($alreadyHave, JSON_UNESCAPED_UNICODE);

        return <<<TXT
Return ONLY valid JSON. No markdown. No extra text.

Target language: {$targetLabel} ({$targetMeta['code']})
Support language: {$supportLabel} ({$supportMeta['code']})

Create EXACTLY {$count} NEW MCQ exercises.
Minimums in this batch:
- at least {$vocabMin} vocabulary
- at least {$grammarMin} grammar
- remaining can be comprehension

CRITICAL anti-generic rule:
Every exercise MUST be anchored to the lesson using ONE anchor from "Allowed anchors".
If an exercise is not anchored, do NOT create it.

Hard rules:
- type="mcq"
- difficulty: "easy" or "medium"
- options: EXACTLY 3
- Exactly ONE option has is_correct=true (boolean)
- question_prompt: {$targetLabel} only
- options[].text: {$targetLabel} only
- instructions: ALWAYS exactly "گزینه درست را انتخاب کنید."
- solution_explanation: {$supportLabel} only, 1 short sentence, max ~110 chars
- options[].explanation: ALWAYS empty string ""

Quality rules:
- Do NOT repeat or closely match anything in "Already have".
- No generic questions like "What is a conversation?" unless it clearly references an anchor.
- Vocabulary skill:
  - question_prompt MUST contain the exact anchor term in single quotes, e.g. What does 'small talk' mean?
  - Ask meaning in THIS context.
- Grammar skill:
  - question_prompt MUST be fill-in-the-blank and contain ___
  - Options are the 3 possible fills.
- Comprehension skill:
  - Ask a concrete fact from the dialogue (who/what/why/where).
  - Must mention at least one anchor word/name.

{$guard}

Schema:
{
  "exercises": [
    {
      "type": "mcq",
      "skill": "vocabulary|grammar|comprehension",
      "difficulty": "easy|medium",
      "question_prompt": "",
      "instructions": "گزینه درست را انتخاب کنید.",
      "solution_explanation": "",
      "options": [
        { "text": "", "is_correct": true, "explanation": "" }
      ]
    }
  ]
}

Allowed anchors (MUST use one per exercise):
{$anchorsBlock}

Already have (DO NOT repeat):
{$haveBlock}

Vocabulary list (prefer these if relevant):
{$wordsBlock}

Grammar list (prefer these if relevant):
{$grammarBlock}
{$customBlock}

Lesson text:
{$text}
TXT;
    }

    protected function normalizeExercises(array $exercises, array $targetMeta, array $supportMeta, array $anchors): array
    {
        $out = [];

        foreach ($exercises as $ex) {
            if (!is_array($ex)) {
                continue;
            }

            $type = (string) ($ex['type'] ?? '');
            if ($type !== 'mcq') {
                continue;
            }

            $skill = (string) ($ex['skill'] ?? '');
            $skill = match ($skill) {
                'vocab' => 'vocabulary',
                'grammer' => 'grammar',
                default => $skill,
            };

            if (!in_array($skill, ['vocabulary', 'grammar', 'comprehension'], true)) {
                continue;
            }

            $difficulty = (string) ($ex['difficulty'] ?? 'easy');
            if (!in_array($difficulty, ['easy', 'medium'], true)) {
                $difficulty = 'easy';
            }

            $question = trim((string) ($ex['question_prompt'] ?? ''));
            if ($question === '') {
                continue;
            }

            if (! $this->questionIsAnchored($skill, $question, $anchors)) {
                continue;
            }

            $instructions = trim((string) ($ex['instructions'] ?? ''));
            if ($instructions === '' || !$this->supportTextLooksValid($instructions, $supportMeta['code'])) {
                $instructions = 'گزینه درست را انتخاب کنید.';
            } else {
                $instructions = 'گزینه درست را انتخاب کنید.';
            }

            $solution = trim((string) ($ex['solution_explanation'] ?? ''));
            if ($solution !== '' && !$this->supportTextLooksValid($solution, $supportMeta['code'])) {
                $solution = '';
            }

            $options = $ex['options'] ?? null;
            if (!is_array($options) || count($options) < 3) {
                continue;
            }

            $optionsOut = [];
            $correctCount = 0;

            foreach (array_slice(array_values($options), 0, 3) as $opt) {
                if (!is_array($opt)) {
                    continue;
                }

                $text = trim((string) ($opt['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $isCorrect = $this->toBool($opt['is_correct'] ?? ($opt['isCorrect'] ?? ($opt['correct'] ?? false)));
                if ($isCorrect) {
                    $correctCount++;
                }

                $optionsOut[] = [
                    'text' => $text,
                    'is_correct' => $isCorrect,
                    'explanation' => '',
                ];
            }

            if (count($optionsOut) !== 3) {
                continue;
            }

            if ($correctCount !== 1) {
                $picked = null;
                foreach ($optionsOut as $i => $o) {
                    if ($o['is_correct'] === true) {
                        $picked = $i;
                        break;
                    }
                }
                if ($picked === null) {
                    $picked = 0;
                }
                foreach ($optionsOut as $i => $o) {
                    $optionsOut[$i]['is_correct'] = ($i === $picked);
                }
            }

            $out[] = [
                'type' => 'mcq',
                'skill' => $skill,
                'difficulty' => $difficulty,
                'question_prompt' => $question,
                'instructions' => $instructions,
                'solution_explanation' => $solution,
                'options' => $optionsOut,
                'meta' => is_array($ex['meta'] ?? null) ? $ex['meta'] : null,
            ];
        }

        return array_values($out);
    }

    protected function questionIsAnchored(string $skill, string $question, array $anchors): bool
    {
        $q = mb_strtolower($question);
        $anchorsLower = [];
        foreach ($anchors as $a) {
            $anchorsLower[] = mb_strtolower((string) $a);
        }

        if ($skill === 'grammar') {
            return str_contains($question, '___');
        }

        if ($skill === 'vocabulary') {
            $term = $this->extractQuotedTerm($question);
            if ($term === null || $term === '') {
                return false;
            }
            $t = mb_strtolower($term);
            foreach ($anchorsLower as $a) {
                if ($a === $t) {
                    return true;
                }
            }
            return false;
        }

        foreach ($anchorsLower as $a) {
            if ($a !== '' && str_contains($q, $a)) {
                return true;
            }
        }

        return false;
    }

    protected function extractQuotedTerm(string $text): ?string
    {
        if (preg_match("/'([^']{2,80})'/u", $text, $m)) {
            return trim((string) ($m[1] ?? ''));
        }
        if (preg_match('/"([^"]{2,80})"/u', $text, $m)) {
            return trim((string) ($m[1] ?? ''));
        }
        return null;
    }

    protected function dedupeExercises(array $exercises): array
    {
        $seen = [];
        $out = [];

        foreach ($exercises as $ex) {
            $q = (string) ($ex['question_prompt'] ?? '');
            $opts = (array) ($ex['options'] ?? []);
            $skill = (string) ($ex['skill'] ?? '');

            $key = $this->normalizeExerciseKey($q, $opts, $skill);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $ex;
        }

        return array_values($out);
    }

    protected function normalizeExerciseKey(string $question, array $options, string $skill): string
    {
        $q = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $question) ?? $question));
        $texts = [];

        $correct = '';
        foreach ($options as $o) {
            if (!is_array($o)) {
                continue;
            }
            $t = trim((string) ($o['text'] ?? ''));
            if ($t === '') {
                continue;
            }
            $texts[] = mb_strtolower(preg_replace('/\s+/u', ' ', $t) ?? $t);

            if (($o['is_correct'] ?? false) === true) {
                $correct = mb_strtolower(preg_replace('/\s+/u', ' ', $t) ?? $t);
            }
        }

        sort($texts);

        return sha1(mb_strtolower($skill) . '|' . $q . '|' . implode('|', $texts) . '|' . $correct);
    }

    protected function selectBalanced(array $exercises, int $count, int $vocabMin, int $grammarMin, array $anchors): array
    {
        $vocab = [];
        $grammar = [];
        $comp = [];

        foreach ($exercises as $ex) {
            $skill = (string) ($ex['skill'] ?? '');
            if ($skill === 'vocabulary') {
                $vocab[] = $ex;
            } elseif ($skill === 'grammar') {
                $grammar[] = $ex;
            } else {
                $comp[] = $ex;
            }
        }

        $selected = [];
        $usedAnchors = [];

        $pushIfOk = function (array $ex) use (&$selected, &$usedAnchors, $anchors, $count) {
            if (count($selected) >= $count) {
                return;
            }

            $anchor = $this->inferAnchorFromQuestion((string) ($ex['skill'] ?? ''), (string) ($ex['question_prompt'] ?? ''), $anchors);
            if ($anchor !== null) {
                $k = mb_strtolower($anchor);
                $limit = count($anchors) <= 14 ? 1 : 2;
                $usedAnchors[$k] = $usedAnchors[$k] ?? 0;
                if ($usedAnchors[$k] >= $limit) {
                    return;
                }
                $usedAnchors[$k]++;
            }

            $selected[] = $ex;
        };

        foreach ($vocab as $ex) {
            if (count($selected) >= $count) {
                break;
            }
            if ($this->countSkill($selected, 'vocabulary') >= $vocabMin) {
                break;
            }
            $pushIfOk($ex);
        }

        foreach ($grammar as $ex) {
            if (count($selected) >= $count) {
                break;
            }
            if ($this->countSkill($selected, 'grammar') >= $grammarMin) {
                break;
            }
            $pushIfOk($ex);
        }

        foreach (array_merge($vocab, $grammar, $comp) as $ex) {
            if (count($selected) >= $count) {
                break;
            }

            $k = $this->normalizeExerciseKey((string) ($ex['question_prompt'] ?? ''), (array) ($ex['options'] ?? []), (string) ($ex['skill'] ?? ''));
            $exists = false;

            foreach ($selected as $s) {
                $k2 = $this->normalizeExerciseKey((string) ($s['question_prompt'] ?? ''), (array) ($s['options'] ?? []), (string) ($s['skill'] ?? ''));
                if ($k2 === $k) {
                    $exists = true;
                    break;
                }
            }

            if ($exists) {
                continue;
            }

            $pushIfOk($ex);
        }

        return array_values($selected);
    }

    protected function inferAnchorFromQuestion(string $skill, string $question, array $anchors): ?string
    {
        if ($skill === 'vocabulary') {
            $term = $this->extractQuotedTerm($question);
            return $term ?: null;
        }

        $q = mb_strtolower($question);
        foreach ($anchors as $a) {
            $a2 = mb_strtolower((string) $a);
            if ($a2 !== '' && str_contains($q, $a2)) {
                return (string) $a;
            }
        }

        return null;
    }

    protected function countSkill(array $items, string $skill): int
    {
        $c = 0;
        foreach ($items as $it) {
            if (($it['skill'] ?? null) === $skill) {
                $c++;
            }
        }
        return $c;
    }

    protected function compactExistingExercises(array $exercises, int $max = 18): array
    {
        $out = [];

        foreach (array_slice($exercises, 0, $max) as $ex) {
            $opts = [];
            foreach ((array) ($ex['options'] ?? []) as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $opts[] = [
                    'text' => (string) ($o['text'] ?? ''),
                    'is_correct' => (bool) ($o['is_correct'] ?? false),
                ];
            }

            $out[] = [
                'skill' => (string) ($ex['skill'] ?? ''),
                'difficulty' => (string) ($ex['difficulty'] ?? ''),
                'question_prompt' => (string) ($ex['question_prompt'] ?? ''),
                'options' => $opts,
            ];
        }

        return $out;
    }

    protected function compactWords(array $words, int $max = 12): array
    {
        $out = [];
        $seen = [];

        foreach ($words as $w) {
            if (!is_array($w)) {
                continue;
            }

            $term = trim((string) ($w['term'] ?? ''));
            if ($term === '') {
                continue;
            }

            $k = mb_strtolower($term);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;

            $meaning = $w['meaning'] ?? null;
            $meaning = is_string($meaning) && trim($meaning) !== '' ? trim($meaning) : null;

            $out[] = [
                'term' => $term,
                'meaning' => $meaning,
            ];

            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    protected function compactGrammar(array $points, int $max = 4): array
    {
        $out = [];
        $seen = [];

        foreach ($points as $p) {
            if (!is_array($p)) {
                continue;
            }

            $id = trim((string) ($p['id'] ?? $p['key'] ?? ''));
            $title = trim((string) ($p['title'] ?? ''));
            $pattern = trim((string) ($p['pattern'] ?? ''));

            if ($id === '' && $title === '') {
                continue;
            }

            $k = mb_strtolower($id !== '' ? $id : $title);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;

            $out[] = [
                'id' => $id !== '' ? $id : null,
                'title' => $title !== '' ? $title : null,
                'pattern' => $pattern !== '' ? $pattern : null,
            ];

            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    protected function buildAnchors(string $text, array $wordsCompact, int $max = 18): array
    {
        $anchors = [];
        $seen = [];

        foreach ($wordsCompact as $w) {
            $t = trim((string) ($w['term'] ?? ''));
            if ($t === '') {
                continue;
            }
            $k = mb_strtolower($t);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $anchors[] = $t;
            }
        }

        $lower = mb_strtolower($text);

        $candidates = [
            'get married',
            'married',
            'wedding',
            'bride',
            'groom',
            'bridesmaid',
            'bridesmaids',
            'flower girl',
            'ring bearer',
            'reception',
            "it's about time",
            'aisle',
            'priest',
        ];

        foreach ($candidates as $c) {
            if (str_contains($lower, $c)) {
                $k = mb_strtolower($c);
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $anchors[] = $c;
                }
            }
        }

        $tokens = preg_split('/\s+/u', mb_strtolower($text));
        $tokens = is_array($tokens) ? $tokens : [];
        $stop = $this->stopwords();

        $bigrams = [];
        $n = count($tokens);

        for ($i = 0; $i < $n - 1; $i++) {
            $a = $tokens[$i] ?? '';
            $b = $tokens[$i + 1] ?? '';
            if ($a === '' || $b === '') {
                continue;
            }
            if (isset($stop[$a]) || isset($stop[$b])) {
                continue;
            }
            if (!preg_match('/^[a-z][a-z\'-]{2,}$/u', $a)) {
                continue;
            }
            if (!preg_match('/^[a-z][a-z\'-]{2,}$/u', $b)) {
                continue;
            }
            $bg = $a . ' ' . $b;
            $bigrams[$bg] = ($bigrams[$bg] ?? 0) + 1;
        }

        arsort($bigrams);

        foreach ($bigrams as $bg => $cnt) {
            if (count($anchors) >= $max) {
                break;
            }
            if ($cnt < 2) {
                break;
            }
            $k = mb_strtolower($bg);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $anchors[] = $bg;
            }
        }

        return array_slice($anchors, 0, $max);
    }

    protected function stopwords(): array
    {
        $words = [
            'the','a','an','and','or','but','so','to','of','in','on','at','for','with','from','by','as','is','are','was','were',
            'be','been','being','i','you','he','she','it','we','they','me','him','her','them','my','your','his','their','our',
            'this','that','these','those','there','here','what','who','when','where','why','how','do','does','did','done',
            'can','could','will','would','should','may','might','must','not','no','yes','just','really','very','about','into',
            'over','under','up','down','out','again','now','then','only','also','too','more','most','much','many','some','any',
            'if','because','while','during','after','before','until','than','then','all','ever','every','one','two','three'
        ];

        $out = [];
        foreach ($words as $w) {
            $out[$w] = true;
        }
        return $out;
    }

    protected function supportTextLooksValid(string $text, string $supportCode): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $code = strtolower(trim($supportCode));

        if (!in_array($code, ['fa', 'ar', 'ur'], true)) {
            if (preg_match('/\p{Arabic}/u', $text)) {
                return false;
            }
        }

        if ($code === 'fa') {
            $arabicMarkers = ['ة', 'ى', 'ً', 'ٌ', 'ٍ', 'َ', 'ُ', 'ِ', 'ّ', 'ْ'];
            $hits = 0;

            foreach ($arabicMarkers as $m) {
                if (str_contains($text, $m)) {
                    $hits++;
                }
            }

            $arabicWords = [' كانت ', ' كان ', ' التي ', ' الذي ', ' هذا ', ' هذه ', ' على ', ' إلى ', ' من ', ' في '];
            foreach ($arabicWords as $w) {
                if (str_contains(' ' . $text . ' ', $w)) {
                    $hits++;
                }
            }

            if ($hits >= 2) {
                return false;
            }
        }

        return true;
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

    protected function normalizeText(string $text): string
    {
        $raw = strip_tags($text);
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $raw));
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
        $t = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($maxChars <= 0) {
            return $t;
        }

        if (mb_strlen($t) <= $maxChars) {
            return $t;
        }

        $half = (int) floor($maxChars / 2);
        $head = mb_substr($t, 0, $half);
        $tail = mb_substr($t, -$half);

        return $head . "\n...\n" . $tail;
    }

    protected function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $v = trim(mb_strtolower($value));
            if ($v === 'true' || $v === '1' || $v === 'yes') {
                return true;
            }
            if ($v === 'false' || $v === '0' || $v === 'no' || $v === '') {
                return false;
            }
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed === true;
    }
}
