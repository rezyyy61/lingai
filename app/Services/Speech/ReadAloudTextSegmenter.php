<?php

namespace App\Services\Speech;

class ReadAloudTextSegmenter
{
    public function segment(string $text, int $sentenceBreakMs = 180, int $paragraphBreakMs = 520, int $maxChars = 800): array
    {
        $paragraphs = $this->paragraphs($text);

        if ($paragraphs === []) {
            return [];
        }

        $segments = [];

        foreach ($paragraphs as $paragraphIndex => $paragraph) {
            $sentences = $this->sentences($paragraph);

            if ($sentences === []) {
                $sentences = $this->splitLongText($paragraph, $maxChars);
            }

            foreach (array_values($sentences) as $sentenceIndex => $sentence) {
                $sentence = $this->normalizeWhitespace($sentence);

                if ($sentence === '') {
                    continue;
                }

                $segments[] = [
                    'text' => $sentence,
                    'pause_ms' => $sentenceIndex === array_key_last($sentences)
                        ? ($paragraphIndex === array_key_last($paragraphs) ? 0 : $paragraphBreakMs)
                        : $sentenceBreakMs,
                ];
            }
        }

        return $segments;
    }

    protected function paragraphs(string $text): array
    {
        $text = $this->normalizeWhitespace($text, true);

        if ($text === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $paragraph) => $this->normalizeWhitespace($paragraph),
            preg_split("/\n{2,}/u", $text) ?: []
        )));
    }

    protected function sentences(string $text): array
    {
        return array_values(array_filter(array_map(
            fn (string $sentence) => $this->normalizeWhitespace($sentence),
            preg_split('/(?<=[\.\!\?\。\؟])(?:["”’\']+)?\s+/u', $text) ?: []
        )));
    }

    protected function splitLongText(string $text, int $maxChars): array
    {
        $clauses = preg_split('/(?<=[,;:])\s+/u', $text) ?: [];
        $clauses = array_values(array_filter(array_map($this->normalizeWhitespace(...), $clauses)));

        if ($clauses === []) {
            return [];
        }

        $chunks = [];
        $buffer = '';

        foreach ($clauses as $clause) {
            if (mb_strlen($clause) > $maxChars) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }

                $chunks = [...$chunks, ...$this->splitByWords($clause, $maxChars)];

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

    protected function splitByWords(string $text, int $maxChars): array
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
}
