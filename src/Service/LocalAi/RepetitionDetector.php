<?php

declare(strict_types=1);

namespace App\Service\LocalAi;

final class RepetitionDetector
{
    public function isLooping(string $text): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
        if (mb_strlen($normalized) < 160) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 35) {
            return false;
        }

        $sameTokenRun = 1;
        for ($index = 1, $count = count($tokens); $index < $count; ++$index) {
            $sameTokenRun = $tokens[$index] === $tokens[$index - 1] ? $sameTokenRun + 1 : 1;
            if ($sameTokenRun >= 10) {
                return true;
            }
        }

        $ngramSize = 7;
        $frequencies = [];
        for ($index = 0, $limit = count($tokens) - $ngramSize; $index <= $limit; ++$index) {
            $ngram = implode(' ', array_slice($tokens, $index, $ngramSize));
            $frequencies[$ngram] = ($frequencies[$ngram] ?? 0) + 1;
            if ($frequencies[$ngram] >= 4) {
                return true;
            }
        }

        if (count($tokens) >= 90) {
            $uniqueRatio = count(array_unique($tokens)) / count($tokens);
            if ($uniqueRatio < 0.16) {
                return true;
            }
        }

        $sentences = array_values(array_filter(array_map('trim', preg_split('/[.!?\n]+/u', $normalized) ?: [])));
        $sentenceCounts = array_count_values($sentences);

        return $sentenceCounts !== [] && max($sentenceCounts) >= 4;
    }
}
