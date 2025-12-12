<?php

namespace App\Services\Youtube;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Log;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException as NoTranscriptFoundExceptionV1;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException as TranscriptsDisabledExceptionV1;
use MrMySQL\YoutubeTranscript\Exceptions\NoTranscriptFoundException as NoTranscriptFoundExceptionV2;
use MrMySQL\YoutubeTranscript\Exceptions\TranscriptsDisabledException as TranscriptsDisabledExceptionV2;

class YoutubeTranscriptService
{
    protected TranscriptListFetcher $fetcher;
    protected Client $http;
    protected bool $debug;

    public function __construct()
    {
        $this->debug = (bool) config('services.youtube_transcript.debug', false);

        $headers = [
            'User-Agent' => (string) config('services.youtube_transcript.user_agent', 'Mozilla/5.0'),
            'Accept-Language' => (string) config('services.youtube_transcript.accept_language', 'en-US,en;q=0.9'),
            'Accept' => (string) config('services.youtube_transcript.accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
            'Referer' => 'https://www.youtube.com/',
            'Origin' => 'https://www.youtube.com',
        ];

        $cookie = trim((string) config('services.youtube_transcript.cookie', ''));
        if ($cookie !== '') {
            $headers['Cookie'] = $cookie;
        }

        $httpClient = new Client([
            'timeout' => (float) config('services.youtube_transcript.timeout', 25),
            'connect_timeout' => (float) config('services.youtube_transcript.connect_timeout', 10),
            'http_errors' => false,
            'allow_redirects' => true,
            'headers' => $headers,
            'on_stats' => function (TransferStats $stats) {
                if (! $this->debug) {
                    return;
                }

                $handlerStats = $stats->getHandlerStats() ?? [];
                Log::info('YoutubeTranscriptService: http stats', [
                    'url' => (string) $stats->getEffectiveUri(),
                    'time' => $stats->getTransferTime(),
                    'handler' => [
                        'http_code' => $handlerStats['http_code'] ?? null,
                        'primary_ip' => $handlerStats['primary_ip'] ?? null,
                        'total_time' => $handlerStats['total_time'] ?? null,
                        'redirect_count' => $handlerStats['redirect_count'] ?? null,
                        'ssl_verify_result' => $handlerStats['ssl_verify_result'] ?? null,
                    ],
                    'error' => $stats->getHandlerErrorData(),
                ]);
            },
        ]);

        $this->http = $httpClient;

        $httpFactory = new HttpFactory();
        $this->fetcher = new TranscriptListFetcher($httpClient, $httpFactory, $httpFactory);
    }

    public function getTranscriptTextFromUrl(string $url, string $language = 'en'): ?string
    {
        $videoId = $this->extractVideoId($url);

        if (! $videoId) {
            Log::warning('YoutubeTranscriptService: could not extract video ID from URL', ['url' => $url]);
            return null;
        }

        try {
            $list = $this->fetcher->fetch($videoId);

            $langTry = array_values(array_unique(array_filter([
                $language,
                $language === 'en' ? 'en-US' : null,
                $language === 'en' ? 'en-GB' : null,
            ])));

            $transcript = $list->findTranscript($langTry);
            $segments = $transcript->fetch();

            $text = $this->segmentsToText($segments);

            if ($text !== '') {
                return $text;
            }

            Log::warning('YoutubeTranscriptService: empty transcript text after fetch', [
                'video_id' => $videoId,
                'language' => $language,
            ]);

        } catch (
        NoTranscriptFoundExceptionV1|NoTranscriptFoundExceptionV2|
        TranscriptsDisabledExceptionV1|TranscriptsDisabledExceptionV2
        $e) {
            Log::warning('YoutubeTranscriptService: transcript unavailable (package)', [
                'video_id' => $videoId,
                'language' => $language,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logThrowableChain($e, [
                'video_id' => $videoId,
                'language' => $language,
            ]);

            report($e);
        }

        $fallback = $this->fetchTranscriptViaPlayerResponse($videoId, $language);

        if ($fallback !== null && trim($fallback) !== '') {
            Log::info('YoutubeTranscriptService: transcript fetched via fallback', [
                'video_id' => $videoId,
                'language' => $language,
                'chars' => mb_strlen($fallback),
            ]);
            return $fallback;
        }

        return null;
    }

    protected function fetchTranscriptViaPlayerResponse(string $videoId, string $language): ?string
    {
        try {
            $watch = $this->http->get('https://www.youtube.com/watch?v=' . $videoId);
            $html = (string) $watch->getBody();

            $player = $this->extractJsonObjectFromHtml($html, 'ytInitialPlayerResponse');
            if (! is_array($player)) {
                if ($this->debug) {
                    Log::warning('YoutubeTranscriptService: fallback: player response not found', ['video_id' => $videoId]);
                }
                return null;
            }

            $tracks = data_get($player, 'captions.playerCaptionsTracklistRenderer.captionTracks', []);
            if (! is_array($tracks) || empty($tracks)) {
                if ($this->debug) {
                    Log::warning('YoutubeTranscriptService: fallback: no captionTracks', [
                        'video_id' => $videoId,
                        'has_captions' => data_get($player, 'captions') !== null,
                    ]);
                }
                return null;
            }

            if ($this->debug) {
                Log::info('YoutubeTranscriptService: fallback: captionTracks found', [
                    'video_id' => $videoId,
                    'count' => count($tracks),
                    'langs' => array_values(array_unique(array_filter(array_map(fn ($t) => $t['languageCode'] ?? null, $tracks)))),
                ]);
            }

            $track = $this->pickBestTrack($tracks, $language) ?? $tracks[0] ?? null;
            $baseUrl = is_array($track) ? ($track['baseUrl'] ?? null) : null;
            if (! is_string($baseUrl) || $baseUrl === '') {
                return null;
            }

            $resp = $this->http->get($baseUrl . '&fmt=json3');
            $json = json_decode((string) $resp->getBody(), true);

            if (! is_array($json)) {
                return null;
            }

            $events = $json['events'] ?? null;
            if (! is_array($events)) {
                return null;
            }

            $parts = [];
            foreach ($events as $ev) {
                $segs = is_array($ev) ? ($ev['segs'] ?? null) : null;
                if (! is_array($segs)) {
                    continue;
                }
                foreach ($segs as $seg) {
                    $t = is_array($seg) ? ($seg['utf8'] ?? '') : '';
                    $t = trim((string) $t);
                    if ($t !== '') {
                        $parts[] = $t;
                    }
                }
            }

            $text = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            if ($this->debug) {
                $this->logThrowableChain($e, ['video_id' => $videoId, 'language' => $language, 'fallback' => true]);
            }
            return null;
        }
    }

    protected function pickBestTrack(array $tracks, string $language): ?array
    {
        $lang = strtolower($language);

        foreach ($tracks as $t) {
            $code = strtolower((string) ($t['languageCode'] ?? ''));
            if ($code === $lang) {
                return $t;
            }
        }

        if ($lang === 'en') {
            foreach ($tracks as $t) {
                $code = strtolower((string) ($t['languageCode'] ?? ''));
                if (str_starts_with($code, 'en-')) {
                    return $t;
                }
            }
        }

        return null;
    }

    protected function segmentsToText(array $segments): string
    {
        $parts = [];

        foreach ($segments as $segment) {
            $text = is_array($segment) ? (string) ($segment['text'] ?? '') : '';
            $text = trim($text);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    protected function extractVideoId(string $url): ?string
    {
        $url = trim($url);

        // youtu.be/<id>
        if (preg_match('~youtu\.be/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        // youtube.com/watch?v=<id>
        $parts = parse_url($url);
        if (is_array($parts) && ($parts['host'] ?? null)) {
            parse_str($parts['query'] ?? '', $q);
            if (! empty($q['v']) && is_string($q['v'])) {
                return $q['v'];
            }
        }

        // youtube.com/embed/<id>
        if (preg_match('~youtube\.com/embed/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        // youtube.com/shorts/<id>
        if (preg_match('~youtube\.com/shorts/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract a JS object like: var ytInitialPlayerResponse = {...};
     * Without fragile regex on nested braces (brace counting).
     */
    protected function extractJsonObjectFromHtml(string $html, string $varName): ?array
    {
        $pos = strpos($html, $varName);
        if ($pos === false) {
            return null;
        }

        $start = strpos($html, '{', $pos);
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inStr = false;
        $escape = false;

        $len = strlen($html);
        for ($i = $start; $i < $len; $i++) {
            $ch = $html[$i];

            if ($inStr) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inStr = true;
                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $jsonStr = substr($html, $start, $i - $start + 1);
                    $data = json_decode($jsonStr, true);
                    return is_array($data) ? $data : null;
                }
            }
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
                        'content_type' => $response->getHeaderLine('Content-Type'),
                        'location' => $response->getHeaderLine('Location'),
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
