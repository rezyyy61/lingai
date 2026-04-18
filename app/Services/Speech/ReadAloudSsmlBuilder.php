<?php

namespace App\Services\Speech;

class ReadAloudSsmlBuilder
{
    public function build(
        string $text,
        string $locale,
        string $voice,
        string $rate,
        ?string $style = null,
        int $sentenceBreakMs = 180,
        int $paragraphBreakMs = 520
    ): string {
        $paragraphs = $this->paragraphs($text);

        if ($paragraphs === []) {
            $paragraphs = [$this->normalizeText($text)];
        }

        $sentenceBreakMs = max(0, $sentenceBreakMs);
        $paragraphBreakMs = max(0, $paragraphBreakMs);
        $paragraphBodies = [];

        foreach ($paragraphs as $paragraph) {
            $sentences = $this->sentences($paragraph);

            if ($sentences === []) {
                $sentences = [$this->normalizeText($paragraph)];
            }

            $sentenceMarkup = array_map(
                fn (string $sentence) => '<s>' . $this->escape($sentence) . '</s>',
                array_values(array_filter($sentences, fn (string $sentence) => $sentence !== ''))
            );

            if ($sentenceMarkup === []) {
                continue;
            }

            $paragraphBodies[] = '<p>' . implode(
                $sentenceBreakMs > 0 ? '<break time="' . $this->escape((string) $sentenceBreakMs) . 'ms"/>' : '',
                $sentenceMarkup
            ) . '</p>';
        }

        $body = implode(
            $paragraphBreakMs > 0 ? '<break time="' . $this->escape((string) $paragraphBreakMs) . 'ms"/>' : '',
            $paragraphBodies
        );

        $styleOpen = $style ? '<mstts:express-as style="' . $this->escape($style) . '">' : '';
        $styleClose = $style ? '</mstts:express-as>' : '';

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="http://www.w3.org/2001/mstts" xml:lang="' . $this->escape($locale) . '">'
            . '<voice name="' . $this->escape($voice) . '">'
            . $styleOpen
            . '<prosody rate="' . $this->escape($rate) . '">'
            . $body
            . '</prosody>'
            . $styleClose
            . '</voice>'
            . '</speak>';
    }

    protected function paragraphs(string $text): array
    {
        $text = $this->normalizeText($text, true);
        $parts = preg_split("/\n{2,}/u", $text) ?: [];

        return array_values(array_filter(array_map(
            fn (string $part) => $this->normalizeText($part),
            $parts
        )));
    }

    protected function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[\.\!\?\。\؟])(?:["”’\']+)?\s+/u', $this->normalizeText($text)) ?: [];

        return array_values(array_filter(array_map(
            fn (string $part) => $this->normalizeText($part),
            $parts
        )));
    }

    protected function normalizeText(string $text, bool $keepParagraphBreaks = false): string
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

    protected function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
