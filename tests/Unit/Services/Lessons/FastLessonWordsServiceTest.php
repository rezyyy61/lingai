<?php

namespace Tests\Unit\Services\Lessons;

use App\Models\Lesson;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmResult;
use App\Services\Ai\Pipelines\ChunkedPromptRunner;
use App\Services\Lessons\FastLessonWordsService;
use App\Services\Lessons\LessonWordCandidateScorer;
use App\Services\Lessons\LessonWordPromptBuilder;
use App\Services\Text\ChunkPlan;
use App\Services\Text\TextChunker;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class FastLessonWordsServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_uses_a_final_full_text_selection_pass_to_choose_better_phrases(): void
    {
        config()->set('services.openai.provider', 'openai');
        config()->set('services.openai.words_min_items', 2);
        config()->set('services.openai.words_max_items', 2);
        config()->set('services.openai.words_final_candidate_limit', 6);

        $lesson = new Lesson([
            'original_text' => 'Emma was in a hurry and almost out of breath when she reached the station.',
            'target_language' => 'en',
            'support_language' => 'fa',
        ]);

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('plan')
            ->once()
            ->andReturn(new ChunkPlan(
                chunks: ['Emma was in a hurry and almost out of breath when she reached the station.'],
                targetWords: 450,
                overlapWords: 12,
                totalWords: 14,
                totalChars: 78,
            ));

        $runner = Mockery::mock(ChunkedPromptRunner::class);
        $runner->shouldReceive('runJson')
            ->once()
            ->andReturn([
                [
                    'json' => [
                        'words' => [
                            [
                                'term' => 'station',
                                'meaning' => 'a place where trains stop',
                                'example_sentence' => 'The station is busy in the morning.',
                                'translation' => '',
                            ],
                            [
                                'term' => 'in a hurry',
                                'meaning' => 'moving quickly because there is not much time',
                                'example_sentence' => 'I left the house in a hurry.',
                                'translation' => '',
                            ],
                            [
                                'term' => 'out of breath',
                                'meaning' => 'breathing hard after effort',
                                'example_sentence' => 'I was out of breath after running upstairs.',
                                'translation' => '',
                            ],
                        ],
                    ],
                ],
            ]);

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn(new LlmResult(
                ok: true,
                status: 200,
                content: null,
                json: [
                    'words' => [
                        [
                            'term' => 'in a hurry',
                            'meaning' => 'moving quickly because there is not much time',
                            'example_sentence' => 'I left the house in a hurry.',
                            'translation' => '',
                        ],
                        [
                            'term' => 'out of breath',
                            'meaning' => 'breathing hard after effort',
                            'example_sentence' => 'I was out of breath after running upstairs.',
                            'translation' => '',
                        ],
                    ],
                ],
                finishReason: 'stop',
                usage: null,
                error: null,
                raw: null,
            ));

        $service = new FastLessonWordsService(
            llm: $llm,
            chunker: $chunker,
            runner: $runner,
            promptBuilder: new LessonWordPromptBuilder(),
            candidateScorer: new LessonWordCandidateScorer(),
        );

        $words = $service->generate($lesson);

        $this->assertSame(['in a hurry', 'out of breath'], array_column($words, 'term'));
    }

    public function test_it_falls_back_to_scored_candidates_when_final_selection_output_is_malformed(): void
    {
        config()->set('services.openai.provider', 'openai');
        config()->set('services.openai.words_min_items', 2);
        config()->set('services.openai.words_max_items', 2);
        config()->set('services.openai.words_final_candidate_limit', 6);

        $lesson = new Lesson([
            'original_text' => 'Emma was Married in a hurry and later felt out of breath.',
            'target_language' => 'en',
            'support_language' => 'fa',
        ]);

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('plan')
            ->once()
            ->andReturn(new ChunkPlan(
                chunks: ['Emma was Married in a hurry and later felt out of breath.'],
                targetWords: 450,
                overlapWords: 12,
                totalWords: 11,
                totalChars: 60,
            ));

        $runner = Mockery::mock(ChunkedPromptRunner::class);
        $runner->shouldReceive('runJson')
            ->once()
            ->andReturn([
                [
                    'json' => [
                        'words' => [
                            [
                                'term' => 'Married',
                                'meaning' => 'to become someone’s husband or wife',
                                'example_sentence' => 'They got married last year.',
                                'translation' => '',
                            ],
                            [
                                'term' => 'in a hurry',
                                'meaning' => 'moving quickly because there is not much time',
                                'example_sentence' => 'I left the house in a hurry.',
                                'translation' => '',
                            ],
                        ],
                    ],
                ],
            ]);

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn(new LlmResult(
                ok: false,
                status: 500,
                content: null,
                json: null,
                finishReason: null,
                usage: null,
                error: ['message' => 'bad output'],
                raw: null,
            ));

        $service = new FastLessonWordsService(
            llm: $llm,
            chunker: $chunker,
            runner: $runner,
            promptBuilder: new LessonWordPromptBuilder(),
            candidateScorer: new LessonWordCandidateScorer(),
        );

        $words = $service->generate($lesson);

        $this->assertCount(2, $words);
        $this->assertSame('in a hurry', $words[0]['term']);
        $this->assertSame('Married', $words[1]['term']);
    }

    public function test_it_preserves_exact_substrings_and_dedupes_final_results(): void
    {
        config()->set('services.openai.provider', 'openai');
        config()->set('services.openai.words_min_items', 1);
        config()->set('services.openai.words_max_items', 1);
        config()->set('services.openai.words_final_candidate_limit', 6);

        $lesson = new Lesson([
            'original_text' => 'You need to deal with stress before it grows.',
            'target_language' => 'en',
            'support_language' => 'fa',
        ]);

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('plan')
            ->once()
            ->andReturn(new ChunkPlan(
                chunks: ['You need to deal with stress before it grows.'],
                targetWords: 450,
                overlapWords: 12,
                totalWords: 9,
                totalChars: 46,
            ));

        $runner = Mockery::mock(ChunkedPromptRunner::class);
        $runner->shouldReceive('runJson')
            ->once()
            ->andReturn([
                [
                    'json' => [
                        'words' => [
                            [
                                'term' => 'deal with',
                                'meaning' => 'to handle a problem or situation',
                                'example_sentence' => 'She knows how to deal with stress at work.',
                                'translation' => '',
                            ],
                            [
                                'term' => 'stress',
                                'meaning' => 'pressure or worry',
                                'example_sentence' => 'Stress can affect your sleep.',
                                'translation' => '',
                            ],
                        ],
                    ],
                ],
            ]);

        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn(new LlmResult(
                ok: true,
                status: 200,
                content: null,
                json: [
                    'words' => [
                        [
                            'term' => 'Deal with',
                            'meaning' => 'to handle a problem or situation',
                            'example_sentence' => 'She knows how to deal with stress at work.',
                            'translation' => '',
                        ],
                        [
                            'term' => 'deal with',
                            'meaning' => 'to handle a problem or situation',
                            'example_sentence' => 'She knows how to deal with stress calmly.',
                            'translation' => '',
                        ],
                    ],
                ],
                finishReason: 'stop',
                usage: null,
                error: null,
                raw: null,
            ));

        $service = new FastLessonWordsService(
            llm: $llm,
            chunker: $chunker,
            runner: $runner,
            promptBuilder: new LessonWordPromptBuilder(),
            candidateScorer: new LessonWordCandidateScorer(),
        );

        $words = $service->generate($lesson);

        $this->assertCount(1, $words);
        $this->assertSame('deal with', $words[0]['term']);
    }
}
