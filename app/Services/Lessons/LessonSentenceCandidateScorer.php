<?php

namespace App\Services\Lessons;

class LessonSentenceCandidateScorer
{
    protected array $weakNarrationSignals = [
        'there was',
        'there were',
        'it was a',
        'it was the',
        'he said',
        'she said',
        'they said',
        'this was',
        'that was',
        'in the story',
        'at that moment',
    ];

    protected array $feelingSignals = [
        'feel', 'felt', 'angry', 'frustrated', 'worried', 'relieved', 'calm', 'happy',
        'sad', 'afraid', 'nervous', 'upset', 'surprised', 'excited', 'tired', 'proud',
    ];

    protected array $reflectionSignals = [
        'maybe', 'sometimes', 'after all', 'turned out', 'better', 'learned', 'realized',
        'decided', 'help', 'find something better', 'not so bad', 'make better use',
    ];

    protected array $everydayExpressionSignals = [
        'need to', 'have to', 'can', 'could', 'want to', 'going to', 'for a moment',
        'in a hurry', 'on time', 'after all', 'another train', 'make better use',
        'not so bad', 'take a breath', 'wait a minute',
    ];

    protected array $weakVisualDescriptionSignals = [
        'looked at the screen',
        'looked at the large screen',
        'looked at the board',
        'eyes moved',
        'moved from one line to another',
        'doors were still open',
        'screen',
        'board',
        'platform',
        'line to another',
    ];

    protected array $weakMechanicalMovementSignals = [
        'walked',
        'looked',
        'watched',
        'turned',
        'moved',
        'opened',
        'closed',
        'stood',
    ];

    public function score(array $item, string $fullText): int
    {
        $text = trim((string) ($item['text'] ?? ''));

        if ($text === '') {
            return PHP_INT_MIN;
        }

        $score = 0;
        $wordCount = $this->wordCount($text);
        $charCount = mb_strlen($text);
        $lower = mb_strtolower($text);

        $score += match (true) {
            $wordCount >= 5 && $wordCount <= 11 => 28,
            $wordCount >= 12 && $wordCount <= 15 => 20,
            $wordCount === 4 || $wordCount === 16 => 10,
            $wordCount === 3 || $wordCount === 17 => 2,
            default => -20,
        };

        $score += match (true) {
            $charCount >= 24 && $charCount <= 90 => 8,
            $charCount >= 16 && $charCount <= 115 => 4,
            default => -5,
        };

        if ($this->endsCleanly($text)) {
            $score += 4;
        }

        if ($this->startsCleanly($text)) {
            $score += 3;
        } else {
            $score -= 5;
        }

        $score -= $this->punctuationPenalty($text);
        $score -= $this->clauseDensityPenalty($text);

        if ($this->hasBalancedPairs($text)) {
            $score += 2;
        } else {
            $score -= 10;
        }

        if ($this->looksLikeQuotedFragment($text)) {
            $score -= 16;
        }

        if (preg_match('/\d/u', $text)) {
            $score -= ($this->looksLikeUsefulExpression($lower) || $this->looksLikeStrongDialogue($text, $lower)) ? 2 : 8;
        }

        if ($this->looksFluentAndRepeatable($lower)) {
            $score += 10;
        }

        if ($this->looksWeakNarration($lower)) {
            $score -= 14;
        }

        if ($this->looksLikeActionOrCause($lower)) {
            $score += 6;
        }

        if ($this->looksEmotionallyClear($lower)) {
            $score += 12;
        }

        if ($this->looksReflectiveOrMemorable($lower)) {
            $score += 12;
        }

        if ($this->looksLikeUsefulExpression($lower)) {
            $score += 10;
        }

        if ($this->looksMemorableForShadowing($text, $lower)) {
            $score += 8;
        }

        if ($this->looksLikeStrongDialogue($text, $lower)) {
            $score += 6;
        }

        if ($this->looksWeakVisualDescription($lower)) {
            $score -= 18;
        }

        if ($this->looksWeakDescriptiveNarration($lower)) {
            $score -= 16;
        }

        if ($this->existsInsideText($text, $fullText)) {
            $score += 4;
        }

        if (trim((string) ($item['translation'] ?? '')) !== '') {
            $score += 2;
        }

        return $score;
    }

    public function shortlist(array $items, string $fullText, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $scored = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $score = $this->score($item, $fullText);

            if ($score === PHP_INT_MIN) {
                continue;
            }

            $item['_quality_score'] = $score;
            $scored[] = $item;
        }

        usort($scored, function (array $a, array $b): int {
            $scoreCompare = ($b['_quality_score'] ?? PHP_INT_MIN) <=> ($a['_quality_score'] ?? PHP_INT_MIN);

            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return $this->wordCount((string) ($a['text'] ?? '')) <=> $this->wordCount((string) ($b['text'] ?? ''));
        });

        $selected = [];
        $deferred = [];

        foreach ($scored as $item) {
            if ($this->isTooSimilarToSelected((string) ($item['text'] ?? ''), $selected)) {
                $deferred[] = $item;
                continue;
            }

            $selected[] = $this->stripScore($item);

            if (count($selected) >= $limit) {
                return $selected;
            }
        }

        foreach ($deferred as $item) {
            $selected[] = $this->stripScore($item);

            if (count($selected) >= $limit) {
                break;
            }
        }

        return array_values(array_slice($selected, 0, $limit));
    }

    public function highQualityCount(array $items, string $fullText, int $threshold): int
    {
        $count = 0;

        foreach ($items as $item) {
            if ($this->score($item, $fullText) >= $threshold) {
                $count++;
            }
        }

        return $count;
    }

    protected function stripScore(array $item): array
    {
        unset($item['_quality_score']);

        return [
            'text' => (string) ($item['text'] ?? ''),
            'translation' => $item['translation'] ?? null,
        ];
    }

    protected function startsCleanly(string $text): bool
    {
        return ! preg_match('/^[,;:\)\]"\']/', $text);
    }

    protected function endsCleanly(string $text): bool
    {
        return (bool) preg_match('/[\.\!\?\؟]$/u', $text);
    }

    protected function punctuationPenalty(string $text): int
    {
        $penalty = 0;
        $commaCount = preg_match_all('/,/u', $text) ?: 0;
        $complexCount = preg_match_all('/[;:()\[\]{}]/u', $text) ?: 0;
        $quoteCount = preg_match_all('/["“”‘’«»]/u', $text) ?: 0;

        $penalty += $commaCount * 4;
        $penalty += $complexCount * 3;

        if ($quoteCount > 0) {
            $penalty += 4;
        }

        return $penalty;
    }

    protected function clauseDensityPenalty(string $text): int
    {
        $matches = preg_match_all('/\b(which|that|because|although|though|while|whereas|since|unless|however)\b/iu', $text) ?: 0;

        return $matches * 3;
    }

    protected function looksFluentAndRepeatable(string $text): bool
    {
        $signals = [
            'i ',
            'you ',
            'we ',
            'he ',
            'she ',
            'they ',
            'can ',
            'need ',
            'want ',
            'feel ',
            'felt ',
            'have ',
            'had ',
            'will ',
            'should ',
        ];

        foreach ($signals as $signal) {
            if (str_starts_with($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    protected function looksWeakNarration(string $text): bool
    {
        foreach ($this->weakNarrationSignals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeActionOrCause(string $text): bool
    {
        $signals = [
            'because',
            'so ',
            'then ',
            'need to',
            'want to',
            'decided to',
            'tried to',
            'feel',
            'felt',
            'missed',
            'left',
            'ran',
            'found',
            'lost',
        ];

        foreach ($signals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    protected function looksEmotionallyClear(string $text): bool
    {
        foreach ($this->feelingSignals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    protected function looksReflectiveOrMemorable(string $text): bool
    {
        foreach ($this->reflectionSignals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeUsefulExpression(string $text): bool
    {
        foreach ($this->everydayExpressionSignals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    protected function looksMemorableForShadowing(string $text, string $lower): bool
    {
        if (preg_match('/\b(can|could|should|need to|have to|decided to|maybe|sometimes)\b/iu', $lower)) {
            return true;
        }

        return $this->wordCount($text) >= 5
            && $this->wordCount($text) <= 13
            && (bool) preg_match('/\b(better|again|after all|for a moment|another)\b/iu', $lower);
    }

    protected function looksLikeStrongDialogue(string $text, string $lower): bool
    {
        if (! $this->looksLikeQuotedFragment($text) && preg_match('/[\.\!\?]$/u', $text)) {
            return preg_match('/\b(i|you|we|there\'s|there is|let\'s|maybe)\b/iu', $lower) === 1;
        }

        return false;
    }

    protected function looksWeakVisualDescription(string $text): bool
    {
        foreach ($this->weakVisualDescriptionSignals as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    protected function looksWeakDescriptiveNarration(string $text): bool
    {
        if ($this->looksWeakVisualDescription($text)) {
            return true;
        }

        $movementHits = 0;

        foreach ($this->weakMechanicalMovementSignals as $signal) {
            if (str_contains($text, $signal)) {
                $movementHits++;
            }
        }

        if ($movementHits >= 2) {
            return true;
        }

        return preg_match('/\b(quickly|slowly|carefully)\b/iu', $text) === 1
            && preg_match('/\b(looked|moved|walked|turned|watched)\b/iu', $text) === 1;
    }

    protected function looksLikeQuotedFragment(string $text): bool
    {
        if ((preg_match_all('/["“”‘’«»]/u', $text) ?: 0) === 1) {
            return true;
        }

        return (bool) preg_match('/^[\'"“”‘’«»]/u', $text) || (bool) preg_match('/[\'"“”‘’«»]$/u', $text);
    }

    protected function hasBalancedPairs(string $text): bool
    {
        return (substr_count($text, '(') === substr_count($text, ')'))
            && (substr_count($text, '[') === substr_count($text, ']'))
            && ((preg_match_all('/["“”«»]/u', $text) ?: 0) % 2 === 0);
    }

    protected function existsInsideText(string $text, string $fullText): bool
    {
        $needle = mb_strtolower(trim($text));
        $haystack = mb_strtolower($fullText);

        return $needle !== '' && str_contains($haystack, $needle);
    }

    protected function isTooSimilarToSelected(string $text, array $selected): bool
    {
        foreach ($selected as $item) {
            $other = (string) ($item['text'] ?? '');

            if ($this->isNearDuplicate($text, $other)) {
                return true;
            }
        }

        return false;
    }

    protected function isNearDuplicate(string $left, string $right): bool
    {
        $leftKey = $this->sentenceKey($left);
        $rightKey = $this->sentenceKey($right);

        if ($leftKey === '' || $rightKey === '') {
            return false;
        }

        if ($leftKey === $rightKey) {
            return true;
        }

        if (str_contains($leftKey, $rightKey) || str_contains($rightKey, $leftKey)) {
            return true;
        }

        $leftTokens = $this->tokenSet($leftKey);
        $rightTokens = $this->tokenSet($rightKey);

        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $intersection = array_intersect($leftTokens, $rightTokens);
        $overlap = count($intersection) / max(1, min(count($leftTokens), count($rightTokens)));

        return $overlap >= 0.8;
    }

    protected function tokenSet(string $text): array
    {
        $parts = preg_split('/\s+/u', $text) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part) => mb_strlen($part) >= 3));

        return array_values(array_unique($parts));
    }

    protected function sentenceKey(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
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
}
