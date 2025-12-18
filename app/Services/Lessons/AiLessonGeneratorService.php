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

    public function generateDialogueOnly(
        string $topic,
        string $goal,
        string $level,
        string $length,
        string $targetLang,
        string $supportLang,
        array $keywords = [],
        string $titleHint = ''
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

        $options = $this->llmOptionsForDialogueOnly($provider);

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
                    $attempt
                ) {
                    return [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $this->promptDialogueOnly(
                            topic: $topic,
                            goal: $goal,
                            level: $level,
                            length: $length,
                            targetMeta: $targetMeta,
                            supportMeta: $supportMeta,
                            keywords: $keywords,
                            titleHint: $titleHint,
                            attempt: $attempt
                        )],
                    ];
                },
                options: $options,
                logContext: [
                    'pipeline' => 'ai_dialogue_only',
                    'provider' => $provider,
                    'attempt' => $attempt,
                    'target_lang' => $targetMeta['code'],
                    'support_lang' => $supportMeta['code'],
                    'level' => $level,
                    'length' => $length,
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

            $pack = $this->normalizeDialoguePack(
                json: $json,
                level: $level,
                targetMeta: $targetMeta,
                supportMeta: $supportMeta
            );

            try {
                $this->validateDialoguePack($pack);
                return $pack;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException($lastError ?: 'Invalid LLM response.');
    }

    protected function llmOptionsForDialogueOnly(string $provider): array
    {
        $responseFormat = $this->responseFormatForDialogueOnly();

        if ($provider === 'azure') {
            return [
                'model' => (string) config('services.openai.azure_deployment_lessons', config('services.openai.azure_deployment_words')),
                'max_output_tokens' => (int) config('services.openai.dialogue_only_max_completion_tokens', 900),
                'temperature' => 0.35,
                'response_format' => $responseFormat,
                'timeout' => (int) config('services.openai.lessons_timeout', 80),
                'connect_timeout' => (int) config('services.openai.lessons_connect_timeout', 10),
            ];
        }

        return [
            'model' => (string) config('services.openai.chat_model', 'gpt-4.1-mini'),
            'max_output_tokens' => (int) config('services.openai.dialogue_only_max_tokens', 900),
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

    protected function promptDialogueOnly(
        string $topic,
        string $goal,
        string $level,
        string $length,
        array $targetMeta,
        array $supportMeta,
        array $keywords,
        string $titleHint,
        int $attempt
    ): string {
        $kw = json_encode(array_values($keywords), JSON_UNESCAPED_UNICODE);

        $titleHintLine = $titleHint !== '' ? "Title hint: {$titleHint}\n" : '';
        $goalLine = $goal !== '' ? "Goal: {$goal}\n" : '';
        $levelLine = $level !== '' ? "CEFR Level: {$level}\n" : "CEFR Level: (not provided)\n";

        $strict = $attempt >= 2
            ? "STRICT: Output must be ONLY JSON. Start with { and end with }. No extra characters.\nSTRICT: Use schema EXACTLY. Do not add keys.\n"
            : "";

        return <<<TXT
Return ONLY valid JSON. No markdown. No extra text. No extra keys.

Use EXACT schema (all keys must exist):
{
  "title": "",
  "dialogue": [{"speaker":"","text":""}],
  "tags": [""]
}

Target language: {$targetMeta['label']} ({$targetMeta['code']})
Support language: {$supportMeta['label']} ({$supportMeta['code']})

You MUST create ONLY a dialogue (no story text).
dialogue rules:
- MUST be a non-empty array of 10–18 items.
- MUST use exactly 2 speakers total across all items.
- Speaker names must be short (max 10 chars), e.g. "Mia" and "Noah".
- Each item must be {"speaker":"", "text":""}
- text must be natural, conversational (1–2 sentences max).
- Keep A1/A2 simple if level is A1/A2.
- Make it useful for speaking practice: greetings, questions, short answers, polite phrases.

Topic: {$topic}
{$goalLine}{$levelLine}{$titleHintLine}
Length: {$length}

Keywords (optional, use naturally if relevant):
{$kw}

tags rules:
- 2–6 tags, English, snake_case or simple words.
- Must be an array of strings.

{$strict}
TXT;
    }

    protected function normalizeDialoguePack(array $json, string $level, array $targetMeta, array $supportMeta): array
    {
        $title = trim((string) ($json['title'] ?? ''));
        if ($title === '') $title = 'Generated dialogue';
        $title = Str::limit($title, 120, '');

        $dialogue = $json['dialogue'] ?? [];
        if (!is_array($dialogue)) $dialogue = [];

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

        $tags = $json['tags'] ?? [];
        if (!is_array($tags)) $tags = [];

        $cleanTags = [];
        foreach ($tags as $t) {
            $t = trim((string) $t);
            if ($t === '') continue;
            $cleanTags[] = Str::limit($t, 30, '');
        }
        $cleanTags = array_values(array_unique($cleanTags));
        $cleanTags = array_slice($cleanTags, 0, 8);

        return [
            'title' => $title,
            'dialogue' => array_slice($dlg, 0, 18),
            'tags' => $cleanTags,
            'meta' => [
                'level' => $level !== '' ? $level : null,
                'target_language' => $targetMeta['code'],
                'support_language' => $supportMeta['code'],
                'options' => [
                    'dialogue_only' => true,
                ],
            ],
        ];
    }

    protected function validateDialoguePack(array $pack): void
    {
        $title = trim((string) ($pack['title'] ?? ''));
        if ($title === '') throw new RuntimeException('Invalid LLM response: title is empty.');

        $dialogue = $pack['dialogue'] ?? [];
        if (!is_array($dialogue)) $dialogue = [];

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
    }

    protected function responseFormatForDialogueOnly(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'dialogue_pack',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['title', 'dialogue', 'tags'],
                    'properties' => [
                        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                        'dialogue' => [
                            'type' => 'array',
                            'minItems' => 10,
                            'maxItems' => 18,
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
