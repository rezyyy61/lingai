<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use Illuminate\Support\Facades\Http;

class LessonGrammarService
{
    public function generateGrammar(Lesson $lesson, ?string $customPrompt = null): array
    {
        if (! $lesson->original_text) {
            return [
                'grammar_points' => [],
                'exercises' => [],
            ];
        }

        $base = rtrim(config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $endpoint = $base . '/chat/completions';

        $targetLanguage = $lesson->target_language ?? config('learning_languages.default_target', 'en');
        $supportLanguage = $lesson->support_language ?? config('learning_languages.default_support', 'en');

        $targetLabel = $this->labelForLanguage($targetLanguage);
        $supportLabel = $this->labelForLanguage($supportLanguage);

        $wordCount = str_word_count(strip_tags($lesson->original_text));

        $customBlock = '';

        if ($customPrompt !== null && trim($customPrompt) !== '') {
            $customBlock = "\n\nAdditional user preferences and instructions for grammar. Follow them only if they do not conflict with the JSON schema or the rules:\n" . $customPrompt . "\n";
        }
        $userContent = <<<EOT
You will extract and teach the most important grammar points from a {$targetLabel} lesson.

The lesson text is in {$targetLabel} (language code: {$targetLanguage}).
The learner's main language is {$supportLabel} (language code: {$supportLanguage}).
The approximate length of the text is {$wordCount} words.

Your task:

1) Choose 1–3 core grammar points that are truly central to this lesson.
2) For each grammar point:
   - Explain it in clear, simple {$supportLabel}, as if you are talking to a motivated but non-expert learner.
   - Show a very short pattern.
   - Give 2–3 very clear example sentences.
   - If possible, include at least 1 example that is very close to the lesson text and 1 extra simple example.
3) Build multiple-choice grammar exercises that go from easy to harder.

Return a single JSON object with this exact shape:

{
  "grammar_points": [
    {
      "id": "short_machine_friendly_key",
      "title": "Short title in {$supportLabel}",
      "level": "A1|A2|B1|B2|C1|C2 or null",
      "description": "Clear explanation in {$supportLabel}. Use short sentences and practical language, not academic theory. Explain WHEN we use this grammar, HOW we build it, and ONE common mistake to avoid.",
      "pattern": "One short pattern, for example: subject + can/could + base verb.",
      "examples": [
        {
          "sentence": "Example sentence ONLY in {$targetLabel}.",
          "translation": "Very natural translation into {$supportLabel}, the way a native speaker would say it in real life.",
          "source": "lesson|extra"
        }
      ],
      "meta": {
        "importance": "high|medium|low",
        "tags": ["present_simple", "polite_request"]
      }
    }
  ],
  "exercises": [
    {
      "grammar_id": "short_machine_friendly_key",
      "difficulty": "easy|medium|hard",
      "type": "mcq",
      "question_prompt": "Question in {$targetLabel} that focuses on this grammar point.",
      "instructions": "Short instructions in {$supportLabel} (or very simple {$targetLabel}).",
      "solution_explanation": "Explanation in {$supportLabel} that clearly tells the learner WHY the correct answer is correct and what to remember for next time.",
      "options": [
        {
          "label": "A",
          "text": "Option text in {$targetLabel}.",
          "is_correct": true,
          "explanation": "Reason in {$supportLabel} why this option is correct or incorrect."
        }
      ]
    }
  ]
}

Example language rules:

- The field "sentence" in each example MUST be written ONLY in {$targetLabel}.
- Never write example sentences in {$supportLabel} in the "sentence" field.
- The "translation" field MUST be in natural {$supportLabel}.
- It is OK if the description in {$supportLabel} talks ABOUT the example, but the example sentence itself must always be in {$targetLabel}.

Pattern rules:

- "pattern" must be a single short line.
- If a grammar point has many possible patterns, choose the most central one.
- Do NOT put multiple unrelated patterns in one grammar point; split them into separate grammar_points if needed.

Example selection rules:

- For each grammar point, choose 2–3 examples.
- At least one example SHOULD be taken from the lesson text or a very close variant (mark it with "source": "lesson").
- At least one example SHOULD be a very simple, clean sentence suitable for the learner's level (mark it with "source": "extra").

Exercise rules:

- All exercises must be multiple-choice ("type": "mcq").
- For each grammar point, create a small progression of exercises:
  - "easy": recognition and very controlled practice (e.g. choose the correct form).
  - "medium": choose the correct sentence or phrase in context.
  - "hard": subtler contrasts or common mistakes.
- Use 3–5 options per exercise.
- Exactly one option must have "is_correct": true.
- All other options must be plausible but wrong and clearly related to the same grammar point (not random grammar).

General rules:

- All sentences and options (examples, question_prompt, options.text) must be in {$targetLabel}.
- All explanations, descriptions and translations must be in natural {$supportLabel}.
- Prefer daily, spoken {$supportLabel} instead of formal, academic language.
- If the original {$targetLabel} sentence is a polite request, the translation must also sound polite and natural in {$supportLabel}, NOT a word-by-word mapping.
- It is better to adapt the sentence slightly than to keep an unnatural literal translation.
- Stay close to the actual lesson text and its topic; do not invent completely unrelated examples.
- Return only JSON, no extra text.

{$customBlock}

Lesson text:

{$lesson->original_text}
EOT;


        $payload = [
            'model' => config('services.openai.chat_model', 'gpt-4.1-mini'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful teacher who extracts and teaches grammar points from a lesson and returns structured JSON. Always follow the JSON schema strictly.',
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        $response = Http::withToken(config('services.openai.key'))
            ->timeout(60)
            ->connectTimeout(10)
            ->post($endpoint, $payload)
            ->throw()
            ->json();

        $content = $response['choices'][0]['message']['content'] ?? '{}';
        $data = json_decode($content, true);

        if (! is_array($data)) {
            $data = [];
        }

        return [
            'grammar_points' => $data['grammar_points'] ?? [],
            'exercises' => $data['exercises'] ?? [],
        ];
    }

    protected function labelForLanguage(string $code): string
    {
        return match (strtolower($code)) {
            'fa', 'fas', 'per' => 'Persian (Farsi)',
            'nl', 'nld', 'dut' => 'Dutch',
            'en', 'eng' => 'English',
            'de', 'ger' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'tr' => 'Turkish',
            'ar' => 'Arabic',
            default => 'the target language',
        };
    }
}
