<?php

namespace Tests\Unit\Services\Lessons;

use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmResult;
use App\Services\Ai\Pipelines\ChunkedPromptRunner;
use App\Services\Lessons\LessonSentenceCandidateScorer;
use App\Services\Lessons\LessonSentenceService;
use App\Services\Text\ChunkPlan;
use App\Services\Text\TextChunker;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class LessonSentenceServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_uses_a_final_full_text_selection_pass_for_shadowing_quality(): void
    {
        config()->set('services.openai.provider', 'openai');
        config()->set('services.openai.shadowing_min_items', 3);
        config()->set('services.openai.shadowing_max_items', 3);
        config()->set('services.openai.shadowing_final_candidate_limit', 6);

        $text = 'I missed the train again. The road was unusually crowded, and because the weather had suddenly changed while everyone was already late, the whole morning felt much harder than it needed to be. I need a minute to think. We can fix this tomorrow.';

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('plan')
            ->once()
            ->andReturn(new ChunkPlan(
                chunks: [$text],
                targetWords: 520,
                overlapWords: 10,
                totalWords: 39,
                totalChars: mb_strlen($text),
            ));

        $runner = Mockery::mock(ChunkedPromptRunner::class);
        $runner->shouldReceive('runJson')
            ->once()
            ->andReturn([
                [
                    'json' => [
                        'sentences' => [
                            ['text' => 'I missed the train again.', 'translation' => ''],
                            ['text' => 'I need a minute to think.', 'translation' => ''],
                            ['text' => 'We can fix this tomorrow.', 'translation' => ''],
                            ['text' => 'The road was unusually crowded, and because the weather had suddenly changed while everyone was already late, the whole morning felt much harder than it needed to be.', 'translation' => ''],
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
                    'sentences' => [
                        ['text' => 'I missed the train again.', 'translation' => ''],
                        ['text' => 'I need a minute to think.', 'translation' => ''],
                        ['text' => 'We can fix this tomorrow.', 'translation' => ''],
                    ],
                ],
                finishReason: 'stop',
                usage: null,
                error: null,
                raw: null,
            ));

        $service = $this->makeService($llm, $chunker, $runner);

        $sentences = $service->generate($text);

        $this->assertEqualsCanonicalizing([
            'I missed the train again.',
            'I need a minute to think.',
            'We can fix this tomorrow.',
        ], array_column($sentences, 'text'));
    }

    public function test_it_rejects_filler_and_keeps_the_output_schema_when_final_output_is_malformed(): void
    {
        config()->set('services.openai.provider', 'openai');
        config()->set('services.openai.shadowing_min_items', 3);
        config()->set('services.openai.shadowing_max_items', 3);
        config()->set('services.openai.shadowing_final_candidate_limit', 6);

        $text = 'Welcome back to the lesson. I need a minute to think.';

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('plan')
            ->once()
            ->andReturn(new ChunkPlan(
                chunks: [$text],
                targetWords: 520,
                overlapWords: 10,
                totalWords: 10,
                totalChars: mb_strlen($text),
            ));

        $runner = Mockery::mock(ChunkedPromptRunner::class);
        $runner->shouldReceive('runJson')
            ->twice()
            ->andReturn(
                [
                    [
                        'json' => [
                            'sentences' => [
                                ['text' => 'Welcome back to the lesson.', 'translation' => ''],
                                ['text' => 'I need a minute to think.', 'translation' => ''],
                                ['translation' => 'missing text'],
                                'bad-item',
                            ],
                        ],
                    ],
                ],
                []
            );

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

        $service = $this->makeService($llm, $chunker, $runner);

        $sentences = $service->generate($text);

        $this->assertCount(1, $sentences);
        $this->assertSame('I need a minute to think.', $sentences[0]['text']);
        $this->assertArrayHasKey('translation', $sentences[0]);
    }

    public function test_it_uses_quality_aware_candidate_fallback_even_when_the_initial_count_is_enough(): void
    {
        config()->set('services.openai.provider', 'openai');
        config()->set('services.openai.shadowing_min_items', 3);
        config()->set('services.openai.shadowing_max_items', 3);
        config()->set('services.openai.shadowing_final_candidate_limit', 6);

        $text = 'There was a moment in the story. It was a kind of difficult day. That was the situation at the station. I missed the train again. I need a minute to think. We can fix this tomorrow.';

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('plan')
            ->once()
            ->andReturn(new ChunkPlan(
                chunks: [$text],
                targetWords: 520,
                overlapWords: 10,
                totalWords: 33,
                totalChars: mb_strlen($text),
            ));

        $runner = Mockery::mock(ChunkedPromptRunner::class);
        $runner->shouldReceive('runJson')
            ->twice()
            ->andReturn(
                [
                    [
                        'json' => [
                            'sentences' => [
                                ['text' => 'There was a moment in the story.', 'translation' => ''],
                                ['text' => 'It was a kind of difficult day.', 'translation' => ''],
                                ['text' => 'That was the situation at the station.', 'translation' => ''],
                            ],
                        ],
                    ],
                ],
                [
                    [
                        'json' => [
                            'sentences' => [
                                ['text' => 'I missed the train again.', 'translation' => ''],
                                ['text' => 'I need a minute to think.', 'translation' => ''],
                                ['text' => 'We can fix this tomorrow.', 'translation' => ''],
                            ],
                        ],
                    ],
                ]
            );

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

        $service = $this->makeService($llm, $chunker, $runner);

        $sentences = $service->generate($text);

        $this->assertEqualsCanonicalizing([
            'I missed the train again.',
            'I need a minute to think.',
            'We can fix this tomorrow.',
        ], array_column($sentences, 'text'));
    }

    public function test_it_keeps_diversity_when_falling_back_to_ranked_candidates(): void
    {
        config()->set('services.openai.provider', 'openai');
        config()->set('services.openai.shadowing_min_items', 3);
        config()->set('services.openai.shadowing_max_items', 3);
        config()->set('services.openai.shadowing_final_candidate_limit', 6);

        $text = 'I missed the train again. I missed the train again today. I need a minute to think. We can fix this tomorrow.';

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('plan')
            ->once()
            ->andReturn(new ChunkPlan(
                chunks: [$text],
                targetWords: 520,
                overlapWords: 10,
                totalWords: 21,
                totalChars: mb_strlen($text),
            ));

        $runner = Mockery::mock(ChunkedPromptRunner::class);
        $runner->shouldReceive('runJson')
            ->once()
            ->andReturn([
                [
                    'json' => [
                        'sentences' => [
                            ['text' => 'I missed the train again.', 'translation' => ''],
                            ['text' => 'I missed the train again today.', 'translation' => ''],
                            ['text' => 'I need a minute to think.', 'translation' => ''],
                            ['text' => 'We can fix this tomorrow.', 'translation' => ''],
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

        $service = $this->makeService($llm, $chunker, $runner);

        $sentences = $service->generate($text);
        $texts = array_column($sentences, 'text');

        $this->assertCount(3, $texts);
        $this->assertContains('I need a minute to think.', $texts);
        $this->assertContains('We can fix this tomorrow.', $texts);
        $this->assertTrue(
            in_array('I missed the train again.', $texts, true) xor in_array('I missed the train again today.', $texts, true)
        );
    }

    public function test_it_prefers_memorable_shadowing_lines_over_plain_visual_description(): void
    {
        config()->set('services.openai.provider', 'openai');
        config()->set('services.openai.shadowing_min_items', 3);
        config()->set('services.openai.shadowing_max_items', 3);
        config()->set('services.openai.shadowing_final_candidate_limit', 8);

        $text = 'She quickly looked at the large screen. Her eyes moved from one line to another. The doors were still open. She felt angry and frustrated for a moment. Maybe this delay is not so bad after all. She decided to make better use of her time.';

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('plan')
            ->once()
            ->andReturn(new ChunkPlan(
                chunks: [$text],
                targetWords: 520,
                overlapWords: 10,
                totalWords: 39,
                totalChars: mb_strlen($text),
            ));

        $runner = Mockery::mock(ChunkedPromptRunner::class);
        $runner->shouldReceive('runJson')
            ->once()
            ->andReturn([
                [
                    'json' => [
                        'sentences' => [
                            ['text' => 'She quickly looked at the large screen.', 'translation' => ''],
                            ['text' => 'Her eyes moved from one line to another.', 'translation' => ''],
                            ['text' => 'The doors were still open.', 'translation' => ''],
                            ['text' => 'She felt angry and frustrated for a moment.', 'translation' => ''],
                            ['text' => 'Maybe this delay is not so bad after all.', 'translation' => ''],
                            ['text' => 'She decided to make better use of her time.', 'translation' => ''],
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

        $service = $this->makeService($llm, $chunker, $runner);

        $sentences = $service->generate($text);

        $this->assertEqualsCanonicalizing([
            'She felt angry and frustrated for a moment.',
            'Maybe this delay is not so bad after all.',
            'She decided to make better use of her time.',
        ], array_column($sentences, 'text'));
    }

    public function test_it_preserves_paragraph_boundaries_and_uses_sentence_aware_shrinking(): void
    {
        $service = new LessonSentenceServiceProxy(
            Mockery::mock(LlmClient::class),
            Mockery::mock(TextChunker::class),
            Mockery::mock(ChunkedPromptRunner::class),
            new LessonSentenceCandidateScorer(),
        );

        $text = <<<TXT
First paragraph has an opening sentence. It also has a second sentence.

Middle paragraph keeps the important shadowing sentence. It should not disappear.

Last paragraph closes the story cleanly. It adds one more sentence.
TXT;

        $normalized = $service->normalizeProxy($text);
        $shrunk = $service->shrinkProxy($text, 170);

        $this->assertStringContainsString("\n\n", $normalized);
        $this->assertStringContainsString('Middle paragraph keeps the important shadowing sentence.', $shrunk);
        $this->assertStringNotContainsString("\n...\n", $shrunk);
    }

    protected function makeService(LlmClient $llm, TextChunker $chunker, ChunkedPromptRunner $runner): LessonSentenceService
    {
        return new LessonSentenceService(
            llm: $llm,
            chunker: $chunker,
            runner: $runner,
            candidateScorer: new LessonSentenceCandidateScorer(),
        );
    }
}

class LessonSentenceServiceProxy extends LessonSentenceService
{
    public function normalizeProxy(string $text): string
    {
        return $this->normalizeText($text);
    }

    public function shrinkProxy(string $text, int $maxChars): string
    {
        return $this->shrinkText($text, $maxChars);
    }
}
