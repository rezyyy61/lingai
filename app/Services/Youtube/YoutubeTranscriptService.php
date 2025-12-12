<?php

namespace App\Services\Youtube;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Log;
use MrMySQL\YoutubeTranscript\Exceptions\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exceptions\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;

class YoutubeTranscriptService
{
    protected TranscriptListFetcher $fetcher;

    public function __construct()
    {
        $httpClient = new Client([
            'timeout' => (float) (config('services.youtube_transcript.timeout', 25)),
            'connect_timeout' => (float) (config('services.youtube_transcript.connect_timeout', 10)),
            'http_errors' => false,
            'allow_redirects' => true,
            'headers' => [
                'User-Agent' => (string) config('services.youtube_transcript.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),
                'Accept-Language' => (string) config('services.youtube_transcript.accept_language', 'en-US,en;q=0.9'),
                'Accept' => (string) config('services.youtube_transcript.accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
            ],
            'on_stats' => function (TransferStats $stats) {
                $handlerStats = $stats->getHandlerStats() ?? [];

                Log::info('YoutubeTranscriptService: http stats', [
                    'url' => (string) $stats->getEffectiveUri(),
                    'time' => $stats->getTransferTime(),
                    'handler' => [
                        'primary_ip' => $handlerStats['primary_ip'] ?? null,
                        'primary_port' => $handlerStats['primary_port'] ?? null,
                        'local_ip' => $handlerStats['local_ip'] ?? null,
                        'http_code' => $handlerStats['http_code'] ?? null,
                        'total_time' => $handlerStats['total_time'] ?? null,
                        'namelookup_time' => $handlerStats['namelookup_time'] ?? null,
                        'connect_time' => $handlerStats['connect_time'] ?? null,
                        'appconnect_time' => $handlerStats['appconnect_time'] ?? null,
                        'starttransfer_time' => $handlerStats['starttransfer_time'] ?? null,
                        'redirect_count' => $handlerStats['redirect_count'] ?? null,
                        'ssl_verify_result' => $handlerStats['ssl_verify_result'] ?? null,
                    ],
                    'error' => $stats->getHandlerErrorData(),
                ]);
            },
        ]);

        $httpFactory = new HttpFactory();

        $this->fetcher = new TranscriptListFetcher($httpClient, $httpFactory, $httpFactory);
    }

    public function getTranscriptTextFromUrl(string $url, string $language = 'en'): ?string
    {
        $videoId = $this->extractVideoId($url);

        if (! $videoId) {
            Log::warning('YoutubeTranscriptService: could not extract video ID from URL', [
                'url' => $url,
            ]);

            return null;
        }

        try {
            $list = $this->fetcher->fetch($videoId);
            $transcript = $list->findTranscript([$language]);
            $segments = $transcript->fetch();

            $parts = [];

            foreach ($segments as $segment) {
                $text = is_array($segment) ? ($segment['text'] ?? '') : '';
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            $text = trim(implode(' ', $parts));

            if ($text === '') {
                Log::warning('YoutubeTranscriptService: empty transcript text', [
                    'video_id' => $videoId,
                    'language' => $language,
                ]);

                return null;
            }

            return $text;
        } catch (NoTranscriptFoundException|TranscriptsDisabledException $e) {
            Log::warning('YoutubeTranscriptService: no transcript or disabled', [
                'video_id' => $videoId,
                'language' => $language,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logThrowableChain($e, [
                'video_id' => $videoId,
                'language' => $language,
            ]);

            report($e);

            return null;
        }
    }

    protected function extractVideoId(string $url): ?string
    {
        if (preg_match('~youtu\.be/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/watch\?v=([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/embed/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function logThrowableChain(\Throwable $e, array $context = []): void
    {
        $chain = [];
        $current = $e;
        $depth = 0;

        while ($current && $depth < 8) {
            $item = [
                'exception' => get_class($current),
                'message' => $current->getMessage(),
                'code' => $current->getCode(),
            ];

            if ($current instanceof RequestException) {
                $response = $current->getResponse();

                if ($response) {
                    $body = (string) $response->getBody();

                    $item['http'] = [
                        'status' => $response->getStatusCode(),
                        'reason' => $response->getReasonPhrase(),
                        'headers' => [
                            'content-type' => $response->getHeaderLine('Content-Type'),
                            'location' => $response->getHeaderLine('Location'),
                            'set-cookie' => $response->getHeader('Set-Cookie'),
                        ],
                        'body_head' => mb_substr($body, 0, 800),
                    ];
                }

                $request = $current->getRequest();

                if ($request) {
                    $item['request'] = [
                        'method' => $request->getMethod(),
                        'uri' => (string) $request->getUri(),
                    ];
                }
            }

            $chain[] = $item;

            $current = $current->getPrevious();
            $depth++;
        }

        Log::error('YoutubeTranscriptService: exception chain', $context + ['chain' => $chain]);
    }
}
