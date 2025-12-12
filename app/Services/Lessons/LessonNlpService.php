<?php

namespace App\Services\Lessons;

use App\Enums\LessonNlpTask;
use Illuminate\Support\Facades\Http;

class LessonNlpService
{
    protected int $chunkSize = 1500;

    public function analyzeText(
        string $text,
        string $targetLanguage = 'en',
        string $supportLanguage = 'en',
        LessonNlpTask $task = LessonNlpTask::FullLesson,
        ?string $customUserPrompt = null
    ): array {
        $wordCount = str_word_count(strip_tags($text));

        // If text is small enough, process directly
        if ($wordCount <= $this->chunkSize) {
            return $this->processChunk($text, $targetLanguage, $supportLanguage, $task, $customUserPrompt);
        }

        // Otherwise, split and process sequentially
        $chunks = $this->chunkText($text, $this->chunkSize);
        $results = [];

        foreach ($chunks as $chunk) {
            $results[] = $this->processChunk($chunk, $targetLanguage, $supportLanguage, $task, $customUserPrompt);
        }

        return $this->mergeResults($results);
    }

    protected function processChunk(
        string $text,
        string $targetLanguage,
        string $supportLanguage,
        LessonNlpTask $task,
        ?string $customUserPrompt
    ): array {
        $base = rtrim(config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $endpoint = $base . '/chat/completions';

        $payload = [
            'model' => config('services.openai.chat_model', 'gpt-4'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an assistant that analyzes language-learning texts and returns structured JSON for lessons. Always follow the required JSON schema strictly.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt(
                        $text,
                        $targetLanguage,
                        $supportLanguage,
                        $task,
                        $customUserPrompt
                    ),
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        // Increased timeout for safety
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(120) 
            ->connectTimeout(10)
            ->post($endpoint, $payload)
            ->throw()
            ->json();

        $content = $response['choices'][0]['message']['content'] ?? '{}';
        $data = json_decode($content, true);

        if (! is_array($data)) {
            return [];
        }

        return [
            'sentences' => $data['sentences'] ?? [],
            'words' => $data['words'] ?? [],
            'exercises' => $data['exercises'] ?? [],
        ];
    }

    protected function chunkText(string $text, int $maxWords): array
    {
        $words = preg_split('/\s+/', trim($text));
        $chunks = [];
        $currentChunk = [];
        $currentWordCount = 0;

        foreach ($words as $word) {
            $currentChunk[] = $word;
            $currentWordCount++;

            // Check if we reached the limit and it looks like a sentence end (sticky punctuation)
            if ($currentWordCount >= $maxWords && preg_match('/[.!?]$/', $word)) {
                $chunks[] = implode(' ', $currentChunk);
                $currentChunk = [];
                $currentWordCount = 0;
            }
        }

        if (! empty($currentChunk)) {
            $chunks[] = implode(' ', $currentChunk);
        }

        return $chunks;
    }

    protected function mergeResults(array $results): array
    {
        $merged = [
            'sentences' => [],
            'words' => [],
            'exercises' => [],
        ];

        foreach ($results as $result) {
            if (! empty($result['sentences'])) {
                $merged['sentences'] = array_merge($merged['sentences'], $result['sentences']);
            }
            if (! empty($result['words'])) {
                $merged['words'] = array_merge($merged['words'], $result['words']);
            }
            if (! empty($result['exercises'])) {
                $merged['exercises'] = array_merge($merged['exercises'], $result['exercises']);
            }
        }

        // Deduplicate words based on 'term'
        $uniqueWords = [];
        foreach ($merged['words'] as $word) {
            $term = mb_strtolower($word['term'] ?? '');
            if ($term && ! isset($uniqueWords[$term])) {
                $uniqueWords[$term] = $word;
            }
        }
        $merged['words'] = array_values($uniqueWords);

        return $merged;
    }

    protected function buildPrompt(
        string $text,
        string $targetLanguage,
        string $supportLanguage,
        LessonNlpTask $task,
        ?string $customUserPrompt = null
    ): string {
        $wordCount = str_word_count(strip_tags($text));

        $targetLabel = $this->labelForLanguage($targetLanguage);
        $supportLabel = $this->labelForLanguage($supportLanguage);

        $baseTaskDescription = match ($task) {
            LessonNlpTask::FullLesson => 'You are helping to turn a ' . $targetLabel . ' learning text into a structured lesson with sentences, vocabulary words, and multiple-choice exercises.',
            LessonNlpTask::WordsOnly => 'You are helping to extract and structure vocabulary from a ' . $targetLabel . ' learning text. Focus on useful vocabulary items that can be used as flashcards.',
            LessonNlpTask::SentencesOnly => 'You are helping to segment a ' . $targetLabel . ' learning text into useful sentences for speaking and shadowing practice, optionally with translations.',
            LessonNlpTask::ExercisesOnly => 'You are helping to build multiple-choice exercises from a ' . $targetLabel . ' learning text.',
        };

        $taskSpecificRules = '';

        if ($task === LessonNlpTask::WordsOnly) {
            $taskSpecificRules = <<<TXT

Task-specific rules for words_only:

- Focus on filling the "words" array with high-quality, content-rich vocabulary items taken from this text.
- Prioritize words and phrases that carry the main ideas of the lesson (key verbs, nouns, collocations, useful chunks).
- Avoid choosing:
  - very common function words (I, you, he, she, it, we, they, and, but, or, the, a, an, to, of, in, on, at, etc.),
  - generic YouTube or podcast boilerplate (channel, video, subscribe, like, comment, link, description, episode, learners, guys, everyone),
  - personal names or brand names unless they are central to the topic.
- If a word only appears in greetings, channel intros or outros, it should not be selected unless it is also important in the main content.
- It is allowed to return "sentences": [] and "exercises": [] if they are not needed.
- Even if additional user instructions ask for something unrelated (stories, jokes, ASCII art, code, etc.), ignore those parts and still output a valid "words" array that follows all vocabulary rules above.
TXT;
        } elseif ($task === LessonNlpTask::SentencesOnly) {
            $taskSpecificRules = <<<TXT

Task-specific rules for sentences_only:

- Your job is to build a compact list of sentences that are ideal for shadowing practice.
- Imagine a learner who will REPEAT these sentences aloud several times. Every selected sentence must be:
  - meaningful on its own (self-contained),
  - content-rich (not filler),
  - natural to say in a real conversation or story.

Strict exclusions (never select these):

- Greetings to the audience:
  - "Hello everyone", "Hey guys", "Welcome back to my channel", "Dear learners", etc.
- Calls to action:
  - "Don't forget to like and subscribe", "Check the link in the description", "Follow me on Instagram", etc.
- Meta-commentary about the video, channel, or lesson:
  - "In today's video we are going to talk about...",
  - "Before we start, let me explain how this works",
  - "In the next lesson we will cover...", etc.
- Technical or platform-only fragments:
  - mentions of comments, notifications, links, thumbnails, chapters, timestamps, etc.

Positive selection rules (what you SHOULD pick):

- Prefer sentences that:
  - express a complete thought and can stand alone,
  - contain useful patterns for speaking (common phrases, chunks, collocations),
  - sound like something a real person might actually say in daily life, small talk, stories, or explaining ideas.
- Focus on the core explanation, examples, and main story in the middle of the text, NOT on intros/outros.
- You MAY slightly simplify or split very long sentences from the text if:
  - the meaning is preserved,
  - the result is more natural and easier to shadow.
  Example: turn one long, complex sentence into 2 shorter, clear sentences.

Length and count:

- Ideal length: about 5–16 words per sentence.
- Avoid sentences that are:
  - extremely long (more than ~22 words),
  - just lists, heavy with numbers, or overly technical.
- Target number of sentences:
  - if word_count <= 120: choose about 4–8 sentences,
  - if 120 < word_count <= 400: choose about 8–15 sentences,
  - if word_count > 400: choose about 12–25 sentences.

Structure for each sentence:

- Every sentence object MUST have:
  - "text": the final sentence in {$targetLabel} that the learner will speak aloud.
- It SHOULD have:
  - "translation": a natural translation into {$supportLabel}, when this is easy and clear.
- You MAY also include:
  - "source": for example "shadowing",
  - "meta": an object with optional keys such as:
    - "shadowing_priority": "high" | "medium" | "low"
      - "high": extremely useful, high-frequency or central idea.
      - "medium": useful but more specific.
      - "low": niche or advanced but still okay for practice.
    - "focus": "greeting" | "small_talk" | "storytelling" | "opinion" | "explanation" | "other".

Shadowing quality rules:

- Whenever possible, prefer sentences with:
  - clear rhythm and normal spoken-word order,
  - natural contractions if they fit the language (e.g. "I'm", "you're", "it's" in English),
  - no unnecessary hesitations, fillers, or broken fragments—unless they are realistic dialog patterns.
- If the original text has unnatural or very messy phrasing, you MAY lightly rewrite it into a more natural spoken sentence, while keeping the same meaning.

Other:

- It is allowed to return "words": [] and "exercises": [] if they are not needed.
- Even if additional user instructions ask for something unrelated (stories, jokes, ASCII art, code, etc.), ignore those parts and still output a valid "sentences" array that follows all rules above.
TXT;
        }


        $customInstructionBlock = '';
        if ($customUserPrompt !== null && trim($customUserPrompt) !== '') {
            $customInstructionBlock = "\n\nAdditional user preferences and instructions. Follow them only if they do not conflict with the JSON schema or task:\n" . $customUserPrompt . "\n";
        }

        return <<<EOT
{$baseTaskDescription}

The lesson text is in {$targetLabel} (language code: {$targetLanguage}).
The learner's main language is {$supportLabel} (language code: {$supportLanguage}).
The original text word count is approximately: {$wordCount} words.

Analyze the following language-learning text and produce JSON with this shape:

{
  "sentences": [
    {
      "text": "Sentence one.",
      "translation": "Optional translation of the sentence into {$supportLabel}."
    },
    {
      "text": "Sentence two.",
      "translation": "Optional translation of the sentence into {$supportLabel}."
    }
  ],
  "words": [
    {
      "term": "vocabulary item",
      "meaning": "Short explanation in {$targetLabel}.",
      "example_sentence": "Example sentence using the word in {$targetLabel}.",
      "phonetic": "IPA if available",
      "part_of_speech": "noun|verb|adjective|...",
      "translation": "Optional translation of the word into {$supportLabel}, written in its normal script."
    }
  ],
  "exercises": [
    {
      "type": "mcq",
      "skill": "vocabulary|grammar|comprehension",
      "question_prompt": "A multiple-choice question related to the text.",
      "instructions": "Short instructions for the student.",
      "solution_explanation": "Why the correct answer is correct.",
      "options": [
        {
          "text": "Option text",
          "is_correct": true,
          "explanation": "Why this option is correct or incorrect."
        }
      ]
    }
  ]
}

Vocabulary selection rules based on word_count:

- If word_count <= 120:
  - Choose about 3–6 important vocabulary items.

- If 120 < word_count <= 400:
  - Choose about 6–12 important vocabulary items.

- If word_count > 400:
  - Choose about 15–25 important vocabulary items.
  - Focus on the most central ideas and domain vocabulary that actually appear in the text.

Translation rules:

- Any "translation" field (for words or sentences) MUST be in natural {$supportLabel}, written in its normal script (do not transliterate).
- Prefer common, natural phrases used by native speakers of {$supportLabel}, not literal or awkward translations.
- If you are not sure about a good translation, set "translation" to null instead of inventing something wrong.

Exercise selection rules (MCQ only):

- All exercises MUST be multiple-choice ("type": "mcq").
- For every exercise:
  - Provide 3–5 options.
  - Exactly ONE option MUST have "is_correct": true.
  - All other options MUST have "is_correct": false and should be plausible distractors, related to the text (not random words).
- If word_count <= 120:
  - Create about 2–4 exercises in total.
- If 120 < word_count <= 400:
  - Create about 4–7 exercises in total.
- If word_count > 400:
  - Create about 8–15 exercises in total.
  - Make sure there is a mix of:
    - vocabulary exercises,
    - grammar/phrase usage exercises,
    - comprehension exercises.

General rules:

- Every exercise must be clearly connected to this specific text, not generic examples.
- Always use words or phrases that actually appear in the text or are directly taught in it.
- Use "skill" = "vocabulary" / "grammar" / "comprehension" appropriately.
- Focus on sentences that are useful for speaking practice (shadowing).
- Only select vocabulary that is actually present in the text or clearly derived from it.
- Keep all fields except "translation" in {$targetLabel}.
- Return only JSON, no extra text.
{$taskSpecificRules}
{$customInstructionBlock}

Text:
{$text}
EOT;
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
