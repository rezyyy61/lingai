<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use Illuminate\Support\Facades\Http;

class LessonAnalysisService
{
    public function generateAnalysis(Lesson $lesson, ?string $customPrompt = null): array
    {
        if (! $lesson->original_text) {
            return [];
        }

        $base = rtrim(config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $endpoint = $base . '/chat/completions';

        $targetLanguage = $lesson->target_language ?? config('learning_languages.default_target', 'en');
        $supportLanguage = $lesson->support_language ?? config('learning_languages.default_support', 'en');

        $targetLabel = $this->labelForLanguage($targetLanguage);
        $supportLabel = $this->labelForLanguage($supportLanguage);

        $wordCount = str_word_count(strip_tags($lesson->original_text));

        $customInstructionBlock = '';

        if ($customPrompt !== null && trim($customPrompt) !== '') {
            $customInstructionBlock = "\n\nAdditional user instructions for this analysis. Follow them only if they do not conflict with the required JSON schema or language rules:\n" . $customPrompt . "\n";
        }

        $userContent = <<<EOT
You will analyze a language-learning lesson.

The lesson text is in {$targetLabel} (language code: {$targetLanguage}).
The learner's main language is {$supportLabel} (language code: {$supportLanguage}).
The approximate length of the text is {$wordCount} words.

Your task:
- Explain the lesson to the learner in {$supportLabel}, using clear and friendly language.
- Focus on the main topic, important grammar points, vocabulary focus, and how to study this lesson effectively with the tools in this app.

Return a single JSON object with this shape:

{
  "overview": "Short, friendly explanation in {$supportLabel} about what this lesson is about.",
  "grammar_points": "Explanation in {$supportLabel} of the most important grammar structures in this lesson. Use paragraphs and, if helpful, short bullet lists.",
  "vocabulary_focus": "Explanation in {$supportLabel} of the key vocabulary themes and useful words/phrases the learner should notice.",
  "study_tips": "Concrete study suggestions in {$supportLabel} that show the learner how to work with this lesson inside the app, including flashcards, shadowing and multiple-choice exercises.",
  "meta": {
    "estimated_level": "A1|A2|B1|B2|C1|C2 or a short description in {$supportLabel}",
    "estimated_time_minutes": 15
  }
}

Rules:
- All fields must be written in natural, learner-friendly {$supportLabel}.
- Do not switch to {$targetLabel}, except for short examples inside quotes if needed.
- In "grammar_points", highlight only the 2–5 most useful grammar ideas for this lesson; do not list every tiny detail.
- In "study_tips", explicitly mention that in this app the learner can:
  - review key words with flashcards,
  - practice speaking by shadowing example sentences,
  - and test understanding with multiple-choice exercises.
- Keep the tone positive, motivating and clear.
- Do not mention that you are an AI model. Speak directly to the learner.

{$customInstructionBlock}

Lesson text:

{$lesson->original_text}
EOT;

        $payload = [
            'model' => config('services.openai.chat_model', 'gpt-4.1-mini'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful teacher who explains language-learning lessons in a clear way and returns structured JSON. Always follow the required JSON schema strictly.',
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
            'overview' => $data['overview'] ?? null,
            'grammar_points' => $data['grammar_points'] ?? null,
            'vocabulary_focus' => $data['vocabulary_focus'] ?? null,
            'study_tips' => $data['study_tips'] ?? null,
            'meta' => $data['meta'] ?? null,
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
