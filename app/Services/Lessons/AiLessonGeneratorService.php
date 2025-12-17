<?php

namespace App\Services\Lessons;

use App\Services\Ai\Pipelines\ChunkedPromptRunner;
use App\Services\Text\ChunkPlan;
use Illuminate\Support\Str;
use RuntimeException;

class AiLessonGeneratorService
{
    public function __construct(
        protected ChunkedPromptRunner $runner,
    ) {}

    public function generate(
        string $topic,
        string $goal,
        string $level,
        string $length,
        string $targetLang,
        string $supportLang,
        array $keywords = [],
        string $titleHint = '',
        bool $includeDialogue = true,
        bool $includeKeyPhrases = true,
        bool $includeQuickQuestions = true
    ): array {
        $topic = trim($topic);
        if ($topic === '') {
            throw new RuntimeException('Topic is required.');
        }

        $provider = (string) config('services.openai.provider', 'openai');

        $targetLang = strtolower(trim($targetLang ?: config('learning_languages.default_target', 'en')));
        $supportLang = strtolower(trim($supportLang ?: config('learning_languages.default_support', 'en')));

        $targetMeta = $this->langMeta($targetLang);
        $supportMeta = $this->langMeta($supportLang);

        $level = trim($level);
        $length = $this->normalizeLength($length);

        $keywords = array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            is_array($keywords) ? $keywords : []
        )));
        $keywords = array_slice($keywords, 0, 12);

        $titleHint = trim($titleHint);
        $goal = trim($goal);

        $options = $this->llmOptionsForLesson($provider, $includeDialogue, $includeKeyPhrases, $includeQuickQuestions);

        $plan = new ChunkPlan(['seed'], 0, 0, 1, 4);

        $lastError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $results = $this->runner->runJson(
                plan: $plan,
                messagesFactory: function () use (
                    $topic,
                    $goal,
                    $level,
                    $length,
                    $targetMeta,
                    $supportMeta,
                    $keywords,
                    $titleHint,
                    $includeDialogue,
                    $includeKeyPhrases,
                    $includeQuickQuestions,
                    $attempt
                ) {
                    return [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $this->promptLesson(
                            topic: $topic,
                            goal: $goal,
                            level: $level,
                            length: $length,
                            targetMeta: $targetMeta,
                            supportMeta: $supportMeta,
                            keywords: $keywords,
                            titleHint: $titleHint,
                            includeDialogue: $includeDialogue,
                            includeKeyPhrases: $includeKeyPhrases,
                            includeQuickQuestions: $includeQuickQuestions,
                            attempt: $attempt
                        )],
                    ];
                },
                options: $options,
                logContext: [
                    'pipeline' => 'ai_lesson_generator',
                    'provider' => $provider,
                    'attempt' => $attempt,
                    'target_lang' => $targetMeta['code'],
                    'support_lang' => $supportMeta['code'],
                    'level' => $level,
                    'length' => $length,
                    'include_dialogue' => $includeDialogue,
                    'include_key_phrases' => $includeKeyPhrases,
                    'include_quick_questions' => $includeQuickQuestions,
                ]
            );

            $json = null;
            foreach ($results as $r) {
                $j = data_get($r, 'json');
                if (is_array($j)) {
                    $json = $j;
                    break;
                }
            }

            if (!is_array($json)) {
                $lastError = 'Invalid LLM response (missing json).';
                continue;
            }

            $pack = $this->normalizePack(
                json: $json,
                level: $level,
                targetMeta: $targetMeta,
                supportMeta: $supportMeta,
                includeDialogue: $includeDialogue,
                includeKeyPhrases: $includeKeyPhrases,
                includeQuickQuestions: $includeQuickQuestions
            );

            try {
                $this->validatePack(
                    pack: $pack,
                    targetMeta: $targetMeta,
                    includeDialogue: $includeDialogue,
                    includeKeyPhrases: $includeKeyPhrases,
                    includeQuickQuestions: $includeQuickQuestions
                );
                return $pack;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException($lastError ?: 'Invalid LLM response.');
    }

    protected function llmOptionsForLesson(
        string $provider,
        bool $includeDialogue,
        bool $includeKeyPhrases,
        bool $includeQuickQuestions
    ): array {
        $responseFormat = $this->responseFormatForLesson($includeDialogue, $includeKeyPhrases, $includeQuickQuestions);

        if ($provider === 'azure') {
            return [
                'model' => (string) config('services.openai.azure_deployment_lessons', config('services.openai.azure_deployment_words')),
                'max_output_tokens' => (int) config('services.openai.lessons_max_completion_tokens', 1800),
                'temperature' => 0.35,
                'response_format' => $responseFormat,
                'timeout' => (int) config('services.openai.lessons_timeout', 80),
                'connect_timeout' => (int) config('services.openai.lessons_connect_timeout', 10),
            ];
        }

        return [
            'model' => (string) config('services.openai.chat_model', 'gpt-4.1-mini'),
            'max_output_tokens' => (int) config('services.openai.lessons_max_tokens', 1800),
            'temperature' => 0.35,
            'response_format' => $responseFormat,
            'timeout' => (int) config('services.openai.lessons_timeout', 80),
            'connect_timeout' => (int) config('services.openai.lessons_connect_timeout', 10),
        ];
    }

    protected function systemPrompt(): string
    {
        return 'Return ONLY valid JSON. No markdown. No extra text. No extra keys.';
    }

    protected function normalizeLength(string $length): string
    {
        $l = strtolower(trim($length));
        if (!in_array($l, ['short', 'medium', 'long'], true)) {
            $l = 'medium';
        }
        return $l;
    }

    protected function lengthTargets(string $length): array
    {
        return match ($length) {
            'short' => ['min' => 180, 'max' => 260],
            'long' => ['min' => 420, 'max' => 620],
            default => ['min' => 280, 'max' => 420],
        };
    }

    protected function promptLesson(
        string $topic,
        string $goal,
        string $level,
        string $length,
        array $targetMeta,
        array $supportMeta,
        array $keywords,
        string $titleHint,
        bool $includeDialogue,
        bool $includeKeyPhrases,
        bool $includeQuickQuestions,
        int $attempt
    ): string {
        $t = $this->lengthTargets($length);
        $kw = json_encode(array_values($keywords), JSON_UNESCAPED_UNICODE);

        $titleHintLine = $titleHint !== '' ? "Title hint: {$titleHint}\n" : '';
        $goalLine = $goal !== '' ? "Goal: {$goal}\n" : '';
        $levelLine = $level !== '' ? "CEFR Level: {$level}\n" : "CEFR Level: (not provided)\n";

        $strict = $attempt >= 2
            ? "STRICT: Output must be ONLY JSON. Start with { and end with }. No extra characters.\nSTRICT: Use schema EXACTLY. Do not add keys.\n"
            : "";

        $dialogueBlock = $includeDialogue
            ? <<<TXT
dialogue rules:
- MUST be a non-empty array of 10–18 items.
- MUST use exactly 2 speakers total across all items.
- speaker must be short (max 10 chars), e.g. "A" and "B" or "Mia" and "Noah".
- Each item must be {"speaker":"", "text":""}
- text must be natural, short, conversational (1–2 sentences max).
- If you cannot follow these rules, fix it and still output valid JSON.
TXT
            : <<<TXT
dialogue rules:
- MUST be an empty array: []
TXT;

        $phrasesBlock = $includeKeyPhrases
            ? <<<TXT
key_phrases rules:
- MUST be an array of 6–10 items.
- Each item must be a single phrase (no numbering, no bullets).
- Must be only in target language.
TXT
            : <<<TXT
key_phrases rules:
- MUST be an empty array: []
TXT;

        $questionsBlock = $includeQuickQuestions
            ? <<<TXT
quick_questions rules:
- MUST be an array of 4–6 items.
- Each item must be a single question sentence.
- Must be only in target language.
TXT
            : <<<TXT
quick_questions rules:
- MUST be an empty array: []
TXT;

        return <<<TXT
Return ONLY valid JSON. No markdown. No extra text. No extra keys.
Use EXACT schema (all keys must exist):
{
  "title": "",
  "lesson_text": "",
  "dialogue": [{"speaker":"","text":""}],
  "key_phrases": [""],
  "quick_questions": [""],
  "tags": [""]
}

Target language: {$targetMeta['label']} ({$targetMeta['code']})
Support language: {$supportMeta['label']} ({$supportMeta['code']})

CRITICAL:
- lesson_text must be ONLY clean paragraphs in target language.
- lesson_text must NOT contain: markdown, **, headings, lists, bullets, numbering, speaker labels like "Anna:", "Key phrases", "Quick questions".
- No translations, no bilingual lines, no romanization.
- Separate paragraphs with a blank line.

Lesson_text requirements:
- 4–8 paragraphs.
- Realistic scenario + useful learning context.
- Natural, non-robotic phrasing.
- Avoid repeating proper names too often (use pronouns).
- Keep it easy to read.

Topic: {$topic}
{$goalLine}{$levelLine}{$titleHintLine}
Length: {$length} (~{$t['min']}–{$t['max']} words)

Keywords (optional, use naturally if relevant):
{$kw}

{$dialogueBlock}

{$phrasesBlock}

{$questionsBlock}

tags rules:
- 2–6 tags, English, snake_case or simple words.
- Must be an array of strings.

{$strict}
TXT;
    }

    protected function normalizePack(
        array $json,
        string $level,
        array $targetMeta,
        array $supportMeta,
        bool $includeDialogue,
        bool $includeKeyPhrases,
        bool $includeQuickQuestions
    ): array {
        $title = trim((string) ($json['title'] ?? ''));
        $lessonText = (string) ($json['lesson_text'] ?? '');

        $dialogue = $json['dialogue'] ?? [];
        $keyPhrases = $json['key_phrases'] ?? [];
        $quickQuestions = $json['quick_questions'] ?? [];
        $tags = $json['tags'] ?? [];

        if (!is_array($dialogue)) $dialogue = [];
        if (!is_array($keyPhrases)) $keyPhrases = [];
        if (!is_array($quickQuestions)) $quickQuestions = [];
        if (!is_array($tags)) $tags = [];

        $cleanTags = [];
        foreach ($tags as $t) {
            $t = trim((string) $t);
            if ($t === '') continue;
            $t = Str::limit($t, 30, '');
            $cleanTags[] = $t;
        }
        $cleanTags = array_values(array_unique($cleanTags));
        $cleanTags = array_slice($cleanTags, 0, 8);

        $kp = [];
        foreach ($keyPhrases as $x) {
            $x = trim((string) $x);
            if ($x === '') continue;
            $x = $this->stripBadInlineTokens($x);
            if ($x === '') continue;
            $kp[] = Str::limit($x, 90, '');
        }
        $kp = array_values(array_unique($kp));
        $kp = array_slice($kp, 0, 12);

        $qq = [];
        foreach ($quickQuestions as $x) {
            $x = trim((string) $x);
            if ($x === '') continue;
            $x = $this->stripBadInlineTokens($x);
            if ($x === '') continue;
            $qq[] = Str::limit($x, 140, '');
        }
        $qq = array_values(array_unique($qq));
        $qq = array_slice($qq, 0, 10);

        $dlg = [];
        foreach ($dialogue as $row) {
            if (!is_array($row)) continue;
            $sp = $this->normalizeSpeakerShort((string) ($row['speaker'] ?? ''));
            $tx = $this->stripBadInlineTokens((string) ($row['text'] ?? ''));
            $tx = trim($tx);
            if ($sp === '' || $tx === '') continue;

            $dlg[] = [
                'speaker' => $sp,
                'text' => Str::limit($tx, 220, ''),
            ];
        }
        $dlg = array_slice($dlg, 0, 24);

        if ($title === '') $title = 'Generated lesson';
        $title = Str::limit($title, 120, '');

        $lessonText = $this->normalizeLessonText($lessonText);

        return [
            'title' => $title,
            'lesson_text' => $lessonText,
            'dialogue' => $dlg,
            'key_phrases' => $kp,
            'quick_questions' => $qq,
            'tags' => $cleanTags,
            'meta' => [
                'level' => $level !== '' ? $level : null,
                'target_language' => $targetMeta['code'],
                'support_language' => $supportMeta['code'],
                'options' => [
                    'include_dialogue' => $includeDialogue,
                    'include_key_phrases' => $includeKeyPhrases,
                    'include_quick_questions' => $includeQuickQuestions,
                ],
            ],
        ];
    }

    protected function validatePack(
        array $pack,
        array $targetMeta,
        bool $includeDialogue,
        bool $includeKeyPhrases,
        bool $includeQuickQuestions
    ): void {
        $title = trim((string) ($pack['title'] ?? ''));
        $lessonText = (string) ($pack['lesson_text'] ?? '');

        if ($title === '') throw new RuntimeException('Invalid LLM response: title is empty.');

        $lessonTextTrim = trim($lessonText);
        if ($lessonTextTrim === '') throw new RuntimeException('Invalid LLM response: lesson_text is empty.');
        if (mb_strlen($lessonTextTrim) < 220) throw new RuntimeException('Invalid LLM response: lesson_text too short.');

        if (strpos($lessonTextTrim, '**') !== false) {
            throw new RuntimeException('Invalid LLM response: lesson_text contains markdown (**).');
        }

        if (preg_match('/(^|\n)\s*[-•]\s+/u', $lessonTextTrim)) {
            throw new RuntimeException('Invalid LLM response: lesson_text contains bullet list.');
        }

        if (preg_match('/(^|\n)\s*\d+\.\s+/u', $lessonTextTrim)) {
            throw new RuntimeException('Invalid LLM response: lesson_text contains numbered list.');
        }

        if ($this->containsSpeakerLabels($lessonTextTrim)) {
            throw new RuntimeException('Invalid LLM response: lesson_text contains speaker labels.');
        }

        if (preg_match('/\b(Key phrases|Quick questions)\b/iu', $lessonTextTrim)) {
            throw new RuntimeException('Invalid LLM response: lesson_text contains section headings text.');
        }

        $paras = preg_split("/\n{2,}/u", $lessonTextTrim) ?: [];
        $paras = array_values(array_filter(array_map(fn ($p) => trim((string) $p), $paras)));
        if (count($paras) < 4) {
            throw new RuntimeException('Invalid LLM response: lesson_text should be 4+ paragraphs.');
        }

        $targetCode = strtolower((string) ($targetMeta['code'] ?? 'en'));
        if ($targetCode !== 'fa' && !in_array($targetCode, ['ar', 'ur'], true)) {
            if (preg_match('/\p{Arabic}/u', $lessonTextTrim)) {
                throw new RuntimeException('Invalid LLM response: contains Arabic-script characters for non-Arabic target.');
            }
        }

        $dialogue = $pack['dialogue'] ?? [];
        if (!is_array($dialogue)) $dialogue = [];

        if ($includeDialogue) {
            if (count($dialogue) < 10) {
                throw new RuntimeException('Invalid LLM response: dialogue missing or too short.');
            }

            $speakers = [];
            foreach ($dialogue as $row) {
                if (!is_array($row)) continue;
                $sp = trim((string) ($row['speaker'] ?? ''));
                $tx = trim((string) ($row['text'] ?? ''));
                if ($sp === '' || $tx === '') {
                    throw new RuntimeException('Invalid LLM response: dialogue item invalid.');
                }
                $speakers[$sp] = true;
            }

            if (count($speakers) !== 2) {
                throw new RuntimeException('Invalid LLM response: dialogue must have exactly 2 speakers.');
            }
        } else {
            if (count($dialogue) > 0) {
                throw new RuntimeException('Invalid LLM response: dialogue must be empty.');
            }
        }

        $keyPhrases = $pack['key_phrases'] ?? [];
        if (!is_array($keyPhrases)) $keyPhrases = [];

        if ($includeKeyPhrases) {
            if (count($keyPhrases) < 6) {
                throw new RuntimeException('Invalid LLM response: key_phrases missing or too short.');
            }
        } else {
            if (count($keyPhrases) > 0) {
                throw new RuntimeException('Invalid LLM response: key_phrases must be empty.');
            }
        }

        $quickQuestions = $pack['quick_questions'] ?? [];
        if (!is_array($quickQuestions)) $quickQuestions = [];

        if ($includeQuickQuestions) {
            if (count($quickQuestions) < 4) {
                throw new RuntimeException('Invalid LLM response: quick_questions missing or too short.');
            }
        } else {
            if (count($quickQuestions) > 0) {
                throw new RuntimeException('Invalid LLM response: quick_questions must be empty.');
            }
        }
    }

    protected function normalizeLessonText(string $text): string
    {
        $raw = strip_tags($text);
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $raw = preg_replace("/[ \t]+/u", ' ', $raw) ?? $raw;
        $raw = preg_replace("/\n[ \t]+/u", "\n", $raw) ?? $raw;

        $out = trim((string) $raw);

        $out = str_replace('**', '', $out);
        $out = preg_replace('/\b(Key phrases|Quick questions)\b/iu', '', $out) ?? $out;

        $out = preg_replace('/(^|\n)\s*[-•]\s+/u', "\n", $out) ?? $out;
        $out = preg_replace('/(^|\n)\s*\d+\.\s+/u', "\n", $out) ?? $out;

        $out = preg_replace($this->speakerLabelRegex(), "\n", $out) ?? $out;

        $out = preg_replace("/\n{3,}/u", "\n\n", $out) ?? $out;
        $out = trim($out);

        return $out;
    }

    protected function stripBadInlineTokens(string $text): string
    {
        $t = strip_tags($text);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace(["\r\n", "\r", "\n"], ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t);

        $t = str_replace('**', '', $t);
        $t = preg_replace('/^\s*[-•]\s+/u', '', $t) ?? $t;
        $t = preg_replace('/^\s*\d+\.\s+/u', '', $t) ?? $t;

        return trim($t);
    }

    protected function normalizeSpeakerShort(string $speaker): string
    {
        $s = trim($speaker);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}\-]/u', '', $s) ?? $s;
        $s = mb_substr($s, 0, 10);
        return trim($s);
    }

    protected function containsSpeakerLabels(string $text): bool
    {
        return (bool) preg_match($this->speakerLabelRegex(), $text);
    }

    protected function speakerLabelRegex(): string
    {
        return '/(^|\n)\s*[\p{L}][\p{L}\p{M}\p{N} \-]{0,20}\:\s+/u';
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

    protected function responseFormatForLesson(
        bool $includeDialogue,
        bool $includeKeyPhrases,
        bool $includeQuickQuestions
    ): array {
        $dialogueMin = $includeDialogue ? 10 : 0;
        $dialogueMax = $includeDialogue ? 18 : 0;

        $phrasesMin = $includeKeyPhrases ? 6 : 0;
        $phrasesMax = $includeKeyPhrases ? 10 : 0;

        $questionsMin = $includeQuickQuestions ? 4 : 0;
        $questionsMax = $includeQuickQuestions ? 6 : 0;

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'lesson_pack',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['title','lesson_text','dialogue','key_phrases','quick_questions','tags'],
                    'properties' => [
                        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                        'lesson_text' => ['type' => 'string', 'minLength' => 220],
                        'dialogue' => [
                            'type' => 'array',
                            'minItems' => $dialogueMin,
                            'maxItems' => $dialogueMax,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['speaker','text'],
                                'properties' => [
                                    'speaker' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10],
                                    'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 220],
                                ],
                            ],
                        ],
                        'key_phrases' => [
                            'type' => 'array',
                            'minItems' => $phrasesMin,
                            'maxItems' => $phrasesMax,
                            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 90],
                        ],
                        'quick_questions' => [
                            'type' => 'array',
                            'minItems' => $questionsMin,
                            'maxItems' => $questionsMax,
                            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 140],
                        ],
                        'tags' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'maxItems' => 6,
                            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30],
                        ],
                    ],
                ],
            ],
        ];
    }
}
