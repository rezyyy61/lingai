<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiLlmClient implements LlmClient
{
    public function chat(array $messages, array $options = []): LlmResult
    {
        $provider = (string) config('services.openai.provider', 'openai');

        [$endpoint, $headers, $meta] = $this->buildEndpointAndHeaders($provider, $options);

        $payload = $this->buildPayload($provider, $messages, $options, $meta);

        try {
            $resp = Http::withHeaders($headers)
                ->timeout((int) ($options['timeout'] ?? config('services.openai.words_timeout', 25)))
                ->connectTimeout((int) ($options['connect_timeout'] ?? config('services.openai.words_connect_timeout', 8)))
                ->post($endpoint, $payload);
        } catch (Throwable $e) {
            Log::error('LlmClient: request exception', [
                'provider' => $provider,
                'endpoint' => $endpoint,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return new LlmResult(false, 0, null, null, null, null, [
                'type' => get_class($e),
                'message' => $e->getMessage(),
            ], null);
        }

        $status = $resp->status();
        $raw = $resp->json();

        if (! $resp->successful()) {
            $bodyHead = mb_substr((string) $resp->body(), 0, 2000);

            Log::warning('LlmClient: request failed', [
                'provider' => $provider,
                'endpoint' => $endpoint,
                'status' => $status,
                'body_head' => $bodyHead,
            ]);

            return new LlmResult(false, $status, null, null, null, data_get($raw, 'usage'), data_get($raw, 'error') ?: [
                'message' => $bodyHead,
            ], is_array($raw) ? $raw : null);
        }

        $content = $this->extractAssistantContent(is_array($raw) ? $raw : []);
        $finishReason = data_get($raw, 'choices.0.finish_reason');
        $usage = data_get($raw, 'usage');

        return new LlmResult(true, $status, $content, null, $finishReason, is_array($usage) ? $usage : null, null, is_array($raw) ? $raw : null);
    }

    public function chatJson(array $messages, array $options = []): LlmResult
    {
        $result = $this->chat($messages, $options);

        if (! $result->ok) {
            return $result;
        }

        $data = $this->decodeJsonObjectLoose($result->content);

        return new LlmResult(
            $result->ok,
            $result->status,
            $result->content,
            $data ?: null,
            $result->finishReason,
            $result->usage,
            $data ? null : [
                'message' => 'Failed to decode JSON from model output',
            ],
            $result->raw
        );
    }

    protected function buildEndpointAndHeaders(string $provider, array $options): array
    {
        if ($provider === 'azure') {
            $endpoint = rtrim((string) config('services.openai.azure_endpoint'), '/');
            $useV1 = (bool) ($options['azure_use_v1'] ?? config('services.openai.azure_use_v1', true));

            $apiVersion = (string) ($options['azure_api_version'] ?? config('services.openai.azure_api_version', '2024-12-01-preview'));
            $deployment = (string) ($options['azure_deployment'] ?? $options['model'] ?? config('services.openai.azure_deployment_words', 'o4-mini'));

            $url = $useV1
                ? "{$endpoint}/openai/v1/chat/completions"
                : "{$endpoint}/openai/deployments/" . rawurlencode($deployment) . "/chat/completions?api-version=" . urlencode($apiVersion);

            return [
                $url,
                [
                    'api-key' => (string) config('services.openai.azure_key'),
                    'Content-Type' => 'application/json',
                ],
                [
                    'azure_use_v1' => $useV1,
                    'azure_deployment' => $deployment,
                    'azure_api_version' => $apiVersion,
                ],
            ];
        }

        $base = rtrim((string) config('services.openai.base', 'https://api.openai.com/v1'), '/');

        return [
            "{$base}/chat/completions",
            [
                'Authorization' => 'Bearer ' . (string) config('services.openai.key'),
                'Content-Type' => 'application/json',
            ],
            [
                'azure_use_v1' => false,
                'azure_deployment' => null,
                'azure_api_version' => null,
            ],
        ];
    }

    protected function buildPayload(string $provider, array $messages, array $options, array $meta): array
    {
        $model = (string) ($options['model'] ?? ($provider === 'azure'
            ? (string) ($meta['azure_deployment'] ?? config('services.openai.azure_deployment_words', 'o4-mini'))
            : (string) config('services.openai.fast_model', 'gpt-4.1-mini')));

        $payload = [
            'messages' => $messages,
        ];

        $azureUseV1 = (bool) ($meta['azure_use_v1'] ?? false);
        if ($provider !== 'azure' || $azureUseV1) {
            $payload['model'] = $model;
        }

        $isReasoningModel = str_starts_with($model, 'o');

        $maxOut =
            (int) ($options['max_output_tokens']
                ?? $options['max_tokens']
                ?? $options['max_completion_tokens']
                ?? 900);

        $useMaxCompletionTokens =
            (bool) ($options['use_max_completion_tokens']
                ?? ($provider === 'azure'
                    ? (bool) config('services.openai.azure_words_use_max_completion_tokens', false)
                    : false));

        if ($isReasoningModel || $useMaxCompletionTokens) {
            $payload['max_completion_tokens'] = $maxOut;
        } else {
            $payload['max_tokens'] = $maxOut;
        }

        $temperature = $options['temperature'] ?? null;
        if (! $isReasoningModel && is_numeric($temperature)) {
            $payload['temperature'] = (float) $temperature;
        }

        if ($isReasoningModel) {
            if ($temperature === 1 || $temperature === '1' || $temperature === 1.0) {
                $payload['temperature'] = 1;
            }
        }

        $reasoningEffort = $options['reasoning_effort'] ?? null;
        if ($isReasoningModel && is_string($reasoningEffort) && $reasoningEffort !== '') {
            $payload['reasoning_effort'] = $reasoningEffort;
        }

        if (array_key_exists('response_format', $options)) {
            $rf = $options['response_format'];
            if ($rf !== null && $rf !== false) {
                $payload['response_format'] = $this->normalizeResponseFormat($rf);
            }
        }

        return $payload;
    }

    protected function normalizeResponseFormat(mixed $rf): array
    {
        if (is_array($rf) && isset($rf['type'])) {
            return $rf;
        }

        if (is_string($rf)) {
            $v = trim($rf);
            if ($v === 'json_object' || $v === 'json') {
                return ['type' => 'json_object'];
            }
        }

        return ['type' => 'json_object'];
    }

    protected function extractAssistantContent(array $json): string
    {
        $content = data_get($json, 'choices.0.message.content');

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                    continue;
                }
                if (is_array($item)) {
                    $t = $item['text'] ?? $item['content'] ?? null;
                    if (is_string($t)) {
                        $parts[] = $t;
                    }
                }
            }
            return trim(implode('', $parts));
        }

        $alt = data_get($json, 'choices.0.text');
        return is_string($alt) ? $alt : '';
    }

    protected function decodeJsonObjectLoose(?string $content): array
    {
        $raw = trim((string) $content);
        if ($raw === '') return [];

        $start = strpos($raw, '{');
        if ($start === false) return [];

        $slice = substr($raw, $start);

        $try = function (string $s): array {
            $data = json_decode($s, true);
            return is_array($data) ? $data : [];
        };

        $data = $try($slice);
        if ($data) return $data;

        $pos = strrpos($slice, '}');
        $attempts = 0;

        while ($pos !== false && $attempts < 40) {
            $attempts++;
            $candidate = substr($slice, 0, $pos + 1);
            $data = $try($candidate);
            if ($data) return $data;
            $pos = strrpos(substr($candidate, 0, -1), '}');
        }

        return [];
    }

}
