<?php

namespace App\Services\Text;

class ChunkPolicy
{
    public function __construct(
        public readonly int $targetWords,
        public readonly int $overlapWords,
        public readonly int $maxChunks,
        public readonly int $timeBudgetMs
    ) {}

    public static function forWords(): self
    {
        return new self(
            targetWords: (int) config('services.openai.words_chunk_target_words', 450),
            overlapWords: (int) config('services.openai.words_chunk_overlap_words', 12),
            maxChunks: (int) config('services.openai.words_chunk_max_chunks', 6),
            timeBudgetMs: (int) config('services.openai.words_time_budget_ms', 55000),
        );
    }

    public static function forSentences(): self
    {
        return new self(
            targetWords: (int) config('services.openai.sentences_chunk_target_words', 520),
            overlapWords: (int) config('services.openai.sentences_chunk_overlap_words', 10),
            maxChunks: (int) config('services.openai.sentences_chunk_max_chunks', 5),
            timeBudgetMs: (int) config('services.openai.sentences_time_budget_ms', 45000),
        );
    }

    public static function forGrammar(): self
    {
        return new self(
            targetWords: (int) config('services.openai.grammar_chunk_target_words', 650),
            overlapWords: (int) config('services.openai.grammar_chunk_overlap_words', 12),
            maxChunks: (int) config('services.openai.grammar_chunk_max_chunks', 4),
            timeBudgetMs: (int) config('services.openai.grammar_time_budget_ms', 50000),
        );
    }

    public static function forExercises(): self
    {
        return new self(
            targetWords: (int) config('services.openai.exercises_chunk_target_words', 520),
            overlapWords: (int) config('services.openai.exercises_chunk_overlap_words', 10),
            maxChunks: (int) config('services.openai.exercises_chunk_max_chunks', 5),
            timeBudgetMs: (int) config('services.openai.exercises_time_budget_ms', 60000),
        );
    }

    public static function forAnalysis(): self
    {
        return new self(
            targetWords: (int) config('services.openai.analysis_chunk_target_words', 650),
            overlapWords: (int) config('services.openai.analysis_chunk_overlap_words', 18),
            maxChunks: (int) config('services.openai.analysis_chunk_max_chunks', 6),
            timeBudgetMs: (int) config('services.openai.analysis_time_budget_ms', 65000),
        );
    }
}
