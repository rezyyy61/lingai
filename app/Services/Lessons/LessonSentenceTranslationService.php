<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonSentence;
use Illuminate\Support\Facades\Http;

class LessonSentenceTranslationService
{
    public function translateForLesson(
        Lesson $lesson,
        bool $onlyMissing = true,
        ?string $customPrompt = null
    ): array {
        $query = $lesson->sentences()->orderBy('order_index');

        if ($onlyMissing) {
            $query->whereNull('translation');
        }

        $sentences = $query->get();

        if ($sentences->isEmpty()) {
            return [];
        }

        $base = rtrim(config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $endpoint = $base . '/chat/completions';

        $targetLanguage = $lesson->target_language ?? config('learning_languages.default_target', 'en');
        $supportLanguage = $lesson->support_language ?? config('learning_languages.default_support', 'en');

        $targetLabel = $this->labelForLanguage($targetLanguage);
        $supportLabel = $this->labelForLanguage($supportLanguage);

        $itemsBlockLines = [];

        foreach ($sentences as $sentence) {
            $itemsBlockLines[] = 'ID: ' . $sentence->id;
            $itemsBlockLines[] = 'Text: ' . $sentence->text;
            $itemsBlockLines[] = '---';
        }

        $itemsBlock = implode("\n", $itemsBlockLines);

        $customInstructionBlock = '';

        if ($customPrompt !== null && trim($customPrompt) !== '') {
            $customInstructionBlock = "\n\nAdditional user instructions for translation. Follow them only if they do not conflict with the required JSON schema or translation rules:\n" . $customPrompt . "\n";
        }

        $userContent = <<<EOT
You will receive a list of sentences in {$targetLabel} (language code: {$targetLanguage}).
Translate each sentence into natural {$supportLabel} (language code: {$supportLanguage}) that is easy to understand for learners.

Return a single JSON object with this shape:

{
  "translations": [
    {
      "id": 123,
      "translation": "Sentence translated into {$supportLabel}."
    }
  ]
}

Rules:
- Use natural, learner-friendly {$supportLabel}. Do not translate word-by-word in an unnatural way.
- Do not add explanations, notes or comments in the translation field.
- If a sentence is incomplete or too strange, still provide the best reasonable translation you can.
- Never invent new sentences; only translate the ones given.
- The "id" must match exactly the sentence ID from the list.

{$customInstructionBlock}

Sentences to translate:

{$itemsBlock}
EOT;

        $payload = [
            'model' => config('services.openai.chat_model', 'gpt-4.1-mini'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an assistant that translates language-learning sentences and returns structured JSON. Always follow the required JSON schema strictly.',
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
            return [];
        }

        $items = $data['translations'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return $items;
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
