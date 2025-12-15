<?php

namespace App\Services\Ai\Pipelines;

use App\Services\Ai\LlmClient;
use App\Services\Text\ChunkPlan;
use Illuminate\Support\Facades\Log;
use ReflectionFunction;
use Throwable;

class ChunkedPromptRunner
{
    public function __construct(
        protected LlmClient $llm
    ) {}

    public function runJson(
        ChunkPlan $plan,
        callable $messagesFactory,
        array $options = [],
        array $logContext = []
    ): array {
        $chunks = $plan->chunks;
        $total = count($chunks);

        if ($total === 0) {
            Log::warning('ChunkedPromptRunner: empty plan', $logContext + [
                    'total_words' => $plan->totalWords,
                    'total_chars' => $plan->totalChars,
                ]);
            return [];
        }

        $t0 = microtime(true);
        $budgetMs = (int) ($logContext['time_budget_ms'] ?? 0);

        $all = [];

        foreach ($chunks as $i => $chunkText) {
            $chunkIndex = $i + 1;

            if ($budgetMs > 0) {
                $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
                if ($elapsedMs >= $budgetMs) {
                    Log::warning('ChunkedPromptRunner: time budget reached', $logContext + [
                            'chunk' => $chunkIndex,
                            'chunks_total' => $total,
                            'elapsed_ms' => $elapsedMs,
                            'results' => count($all),
                        ]);
                    break;
                }
            }

            try {
                $messages = $this->callMessagesFactory($messagesFactory, $chunkText, $chunkIndex, $total);
                $res = $this->llm->chatJson($messages, $options);
            } catch (Throwable $e) {
                Log::warning('ChunkedPromptRunner: chunk exception', $logContext + [
                        'chunk' => $chunkIndex,
                        'chunks_total' => $total,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                continue;
            }

            if ($res->ok && $res->json === null && is_string($res->content) && trim($res->content) !== '') {
                if (($res->finishReason ?? '') === 'length') {
                    $repaired = $this->repairTruncatedJson((string) $res->content, $options);

                    if ($repaired->ok && is_array($repaired->json)) {
                        $res = $repaired;
                    }
                }
            }

            if (! $res->ok || ! is_array($res->json)) {
                Log::warning('ChunkedPromptRunner: chunk failed', $logContext + [
                        'chunk' => $chunkIndex,
                        'chunks_total' => $total,
                        'status' => $res->status,
                        'error' => $res->error,
                        'finish_reason' => $res->finishReason,
                        'usage' => $res->usage,
                        'content_head' => mb_substr((string) $res->content, 0, 1200),
                    ]);
                continue;
            }

            $all[] = [
                'chunk' => $chunkIndex,
                'chunks_total' => $total,
                'json' => $res->json,
                'finish_reason' => $res->finishReason,
                'usage' => $res->usage,
            ];
        }

        return $all;
    }

    protected function callMessagesFactory(callable $factory, string $text, int $chunkIndex, int $chunksTotal): array
    {
        try {
            $rf = new ReflectionFunction($factory);
            $argc = $rf->getNumberOfParameters();
        } catch (Throwable) {
            $argc = 1;
        }

        if ($argc >= 3) {
            return (array) $factory($text, $chunkIndex, $chunksTotal);
        }

        if ($argc === 2) {
            return (array) $factory($text, $chunkIndex);
        }

        return (array) $factory($text);
    }

    protected function repairTruncatedJson(string $partial, array $options)
    {
        $partial = trim($partial);
        if ($partial === '') {
            return $this->llm->chatJson([['role' => 'user', 'content' => '{}']], $options);
        }

        if (mb_strlen($partial) > 12000) {
            $head = mb_substr($partial, 0, 6000);
            $tail = mb_substr($partial, -6000);
            $partial = $head . "\n...\n" . $tail;
        }

        $repairOptions = $options;
        $repairOptions['temperature'] = 0;
        $repairOptions['max_output_tokens'] = (int) ($options['repair_max_output_tokens'] ?? 600);
        $repairOptions['response_format'] = ['type' => 'json_object'];

        $messages = [
            [
                'role' => 'system',
                'content' => 'Return ONLY a valid JSON object. No markdown. No extra text.',
            ],
            [
                'role' => 'user',
                'content' =>
                    "You are given PARTIAL JSON output that was cut off.\n" .
                    "Return a valid JSON object of the SAME schema.\n" .
                    "Do NOT add new items.\n" .
                    "If the last item is incomplete, remove it.\n" .
                    "Close arrays/objects properly.\n" .
                    "Output ONLY JSON.\n\n" .
                    $partial,
            ],
        ];

        return $this->llm->chatJson($messages, $repairOptions);
    }
}
