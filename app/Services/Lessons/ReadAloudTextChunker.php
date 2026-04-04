<?php

namespace App\Services\Lessons;

class ReadAloudTextChunker
{
    public function chunk(string $text): array
    {
        $paragraphs = $this->paragraphs($text);
        $maxChars = $this->maxChars();
        $chunks = [];

        foreach ($paragraphs as $paragraph) {
            if (mb_strlen($paragraph) <= $maxChars) {
                $chunks[] = $paragraph;
                continue;
            }

            $chunks = [...$chunks, ...$this->chunkParagraph($paragraph, $maxChars)];
        }

        return array_values(array_filter($chunks, fn (string $chunk) => trim($chunk) !== ''));
    }

    protected function chunkParagraph(string $paragraph, int $maxChars): array
    {
        $sentences = $this->sentences($paragraph);

        if ($sentences === []) {
            return $this->splitLongSentence($paragraph, $maxChars);
        }

        $chunks = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            if (mb_strlen($sentence) > $maxChars) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }

                $chunks = [...$chunks, ...$this->splitLongSentence($sentence, $maxChars)];
                continue;
            }

            if ($buffer === '') {
                $buffer = $sentence;
                continue;
            }

            $candidate = $buffer . ' ' . $sentence;

            if (mb_strlen($candidate) <= $maxChars) {
                $buffer = $candidate;
                continue;
            }

            $chunks[] = $buffer;
            $buffer = $sentence;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return array_values(array_filter($chunks, fn (string $chunk) => trim($chunk) !== ''));
    }

    protected function paragraphs(string $text): array
    {
        $text = $this->normalizeWhitespace($text, true);

        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [];

        return array_values(array_filter(array_map(
            fn (string $paragraph) => $this->normalizeWhitespace($paragraph),
            $paragraphs
        )));
    }

    protected function sentences(string $paragraph): array
    {
        $sentences = preg_split('/(?<=[\.\!\?\。\؟])(?:["”’\']+)?\s+/u', $paragraph) ?: [];

        return array_values(array_filter(array_map(
            fn (string $sentence) => $this->normalizeWhitespace($sentence),
            $sentences
        )));
    }

    protected function splitLongSentence(string $text, int $maxChars): array
    {
        $clauses = preg_split('/(?<=[,;:])\s+/u', $text) ?: [];

        if (count($clauses) > 1) {
            $chunks = [];
            $buffer = '';

            foreach ($clauses as $clause) {
                $clause = $this->normalizeWhitespace($clause);

                if ($clause === '') {
                    continue;
                }

                if (mb_strlen($clause) > $maxChars) {
                    if ($buffer !== '') {
                        $chunks[] = $buffer;
                        $buffer = '';
                    }

                    $chunks = [...$chunks, ...$this->splitLongSentenceByWords($clause, $maxChars)];
                    continue;
                }

                if ($buffer === '') {
                    $buffer = $clause;
                    continue;
                }

                $candidate = $buffer . ' ' . $clause;

                if (mb_strlen($candidate) <= $maxChars) {
                    $buffer = $candidate;
                    continue;
                }

                $chunks[] = $buffer;
                $buffer = $clause;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
            }

            return $chunks;
        }

        return $this->splitLongSentenceByWords($text, $maxChars);
    }

    protected function splitLongSentenceByWords(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $chunks = [];
        $buffer = '';

        foreach ($words as $word) {
            $word = trim($word);

            if ($word === '') {
                continue;
            }

            if ($buffer === '') {
                $buffer = $word;
                continue;
            }

            $candidate = $buffer . ' ' . $word;

            if (mb_strlen($candidate) <= $maxChars) {
                $buffer = $candidate;
                continue;
            }

            $chunks[] = $buffer;
            $buffer = $word;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    protected function normalizeWhitespace(string $text, bool $keepParagraphBreaks = false): string
    {
        $text = html_entity_decode(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        if ($keepParagraphBreaks) {
            $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
            $text = preg_replace("/ *\n */u", "\n", $text) ?? $text;

            return trim($text);
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function maxChars(): int
    {
        return max(180, (int) config('lesson_generation.read_aloud.chunk.max_chars', 420));
    }
}
