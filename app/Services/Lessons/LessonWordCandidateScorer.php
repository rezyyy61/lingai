<?php

namespace App\Services\Lessons;

class LessonWordCandidateScorer
{
    protected array $weakSingleWordTerms = [
        'thing', 'things', 'good', 'bad', 'nice', 'big', 'small', 'time', 'people', 'person',
        'way', 'day', 'year', 'part', 'kind', 'lot', 'man', 'woman', 'city', 'place', 'story',
    ];

    public function score(array $item, string $fullText): int
    {
        $term = trim((string) ($item['term'] ?? ''));

        if ($term === '') {
            return PHP_INT_MIN;
        }

        $fullLower = mb_strtolower($fullText);
        $termLower = mb_strtolower($term);
        $score = 0;
        $wordCount = $this->wordCount($term);
        $frequency = $termLower !== '' ? substr_count($fullLower, $termLower) : 0;

        $score += min(12, $frequency * 3);

        if ($wordCount >= 2 && $wordCount <= 4) {
            $score += 18;
        } elseif ($wordCount === 5) {
            $score += 10;
        } elseif ($wordCount === 1) {
            $score -= 6;
        }

        if (str_contains($term, ' ')) {
            $score += 6;
        }

        if (str_contains($term, '-') || str_contains($term, '\'')) {
            $score += 3;
        }

        if (preg_match('/^[A-Z][\p{L}\-\'’]+(?:\s+[A-Z][\p{L}\-\'’]+)*$/u', $term)) {
            $score -= 18;
        }

        if (preg_match('/\d/u', $term)) {
            $score -= 20;
        }

        if ($wordCount === 1 && in_array($termLower, $this->weakSingleWordTerms, true)) {
            $score -= 20;
        }

        if ($this->looksLikeFunctionWordPhrase($termLower)) {
            $score -= 16;
        }

        if ($this->looksReusablePhrase($termLower)) {
            $score += 12;
        }

        if ($this->looksContextImportant($item)) {
            $score += 6;
        }

        if (trim((string) ($item['translation'] ?? '')) !== '') {
            $score += 3;
        }

        return $score;
    }

    public function shortlist(array $items, string $fullText, int $limit): array
    {
        $limit = max(1, $limit);

        usort($items, function (array $a, array $b) use ($fullText): int {
            return $this->score($b, $fullText) <=> $this->score($a, $fullText);
        });

        return array_values(array_slice($items, 0, $limit));
    }

    protected function wordCount(string $text): int
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text);

        return is_array($parts) ? count($parts) : 0;
    }

    protected function looksReusablePhrase(string $term): bool
    {
        $signals = [
            'looked at', 'out of', 'in a hurry', 'on time', 'as fast as',
            'take care', 'find out', 'set up', 'end up', 'pick up',
            'go through', 'turn out', 'deal with', 'wait for', 'run out',
        ];

        foreach ($signals as $signal) {
            if (str_contains($term, $signal)) {
                return true;
            }
        }

        return false;
    }

    protected function looksContextImportant(array $item): bool
    {
        $meaning = trim((string) ($item['meaning'] ?? ''));
        $example = trim((string) ($item['example_sentence'] ?? ''));

        return $meaning !== '' && $example !== '';
    }

    protected function looksLikeFunctionWordPhrase(string $term): bool
    {
        $bad = [
            'of the', 'in the', 'on the', 'at the', 'to the', 'for the',
            'and the', 'with the', 'from the', 'it was', 'there was',
        ];

        return in_array($term, $bad, true);
    }
}
