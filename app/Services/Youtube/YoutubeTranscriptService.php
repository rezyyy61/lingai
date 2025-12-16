<?php

namespace App\Services\Youtube;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Log;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;
use Throwable;

class YoutubeTranscriptService
{
    protected TranscriptListFetcher $fetcher;

    public function __construct()
    {
        $headers = [
            'User-Agent' => (string) config('services.youtube_transcript.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),
            'Accept-Language' => (string) config('services.youtube_transcript.accept_language', 'en-US,en;q=0.9'),
            'Accept' => (string) config('services.youtube_transcript.accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
        ];

        $cookie = trim((string) config('services.youtube_transcript.cookie', ''));
        if ($cookie !== '') {
            $headers['Cookie'] = $cookie;
        }

        $httpClient = new Client([
            'timeout' => (float) (config('services.youtube_transcript.timeout', 25)),
            'connect_timeout' => (float) (config('services.youtube_transcript.connect_timeout', 10)),
            'http_errors' => false,
            'allow_redirects' => true,
            'headers' => $headers,
            'on_stats' => function (TransferStats $stats) {
                if (! config('services.youtube_transcript.debug', false)) {
                    return;
                }

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
            $prevKey = null;

            foreach ($segments as $segment) {
                $raw = is_array($segment) ? (string) ($segment['text'] ?? '') : '';
                if ($raw === '') {
                    continue;
                }

                $clean = $this->cleanCaptionChunk($raw);
                if ($clean === '') {
                    continue;
                }

                $key = $this->dedupeKey($clean);
                if ($prevKey !== null && $key === $prevKey) {
                    continue;
                }

                $prevKey = $key;
                $parts[] = $clean;
            }

            $text = $this->finalSanitizeTranscript(trim(implode(' ', $parts)));

            if ($text !== '') {
                return $text;
            }
        } catch (NoTranscriptFoundException|TranscriptsDisabledException $e) {
            Log::warning('YoutubeTranscriptService: transcript unavailable (package)', [
                'video_id' => $videoId,
                'language' => $language,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->logThrowableChain($e, [
                'video_id' => $videoId,
                'language' => $language,
            ]);

            report($e);
        }

        $fallback = $this->fetchTranscriptViaYtDlp($url, $language);

        if ($fallback !== null) {
            return $fallback;
        }

        return null;
    }

    protected function fetchTranscriptViaYtDlp(string $url, string $language): ?string
    {
        $bin = trim((string) config('services.youtube_transcript.yt_dlp_bin', '/opt/yt/bin/yt-dlp'));
        if ($bin === '') {
            return null;
        }

        $baseDir = storage_path('app/tmp/ytdlp_subs');
        if (! is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }

        $runKey = bin2hex(random_bytes(10));
        $runDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $runKey;
        @mkdir($runDir, 0775, true);

        $langs = $language . '.*';
        if (strtolower($language) !== 'en') {
            $langs .= ',en.*';
        }

        $outTemplate = rtrim($runDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '%(id)s.%(ext)s';

        $args = [
            $bin,
            '--skip-download',
            '--no-playlist',
            '--write-subs',
            '--write-auto-subs',
            '--sub-langs', $langs,
            '--sub-format', 'vtt',
            '--convert-subs', 'vtt',
            '-o', $outTemplate,
        ];

        $jsRuntimes = trim((string) config('services.youtube_transcript.js_runtimes', 'node:/usr/bin/node'));
        if ($jsRuntimes !== '') {
            array_splice($args, 1, 0, ['--js-runtimes', $jsRuntimes]);
        }

        $remoteComponents = trim((string) config('services.youtube_transcript.remote_components', ''));
        if ($remoteComponents !== '') {
            array_splice($args, 1, 0, ['--remote-components', $remoteComponents]);
        }

        $cookiesFile = trim((string) config('services.youtube_transcript.cookies_file', ''));
        if ($cookiesFile !== '' && is_file($cookiesFile)) {
            $cookieCopy = rtrim($runDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'youtube-cookies.txt';
            @copy($cookiesFile, $cookieCopy);
            if (is_file($cookieCopy)) {
                array_splice($args, 1, 0, ['--cookies', $cookieCopy]);
            }
        }

        $ua = trim((string) config('services.youtube_transcript.user_agent', ''));
        if ($ua !== '') {
            array_splice($args, 1, 0, ['--user-agent', $ua]);
        }

        $al = trim((string) config('services.youtube_transcript.accept_language', ''));
        if ($al !== '') {
            array_splice($args, 1, 0, ['--add-header', 'Accept-Language: ' . $al]);
        }

        $args[] = $url;

        $timeout = (int) config('services.youtube_transcript.yt_dlp_timeout', 45);
        $timeout = max(10, min(180, $timeout));

        $cmd = $this->buildShellCommand($args);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes, $runDir);

        if (! is_resource($proc)) {
            $this->cleanupDir($runDir);
            Log::warning('YoutubeTranscriptService: yt-dlp failed to start', [
                'bin' => $bin,
            ]);
            return null;
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($proc);
            if (! ($status['running'] ?? false)) {
                break;
            }

            if ((microtime(true) - $start) > $timeout) {
                proc_terminate($proc, 9);
                break;
            }

            usleep(20000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($proc);

        $vttPath = $this->findBestSubtitleFile($runDir);

        if (! $vttPath) {
            Log::warning('YoutubeTranscriptService: yt-dlp no subtitles', [
                'url' => $url,
                'language' => $language,
                'exit' => $exit,
                'stderr_head' => mb_substr($stderr, 0, 1200),
                'stdout_head' => mb_substr($stdout, 0, 600),
            ]);
            $this->cleanupDir($runDir);
            return null;
        }

        $raw = @file_get_contents($vttPath);
        $text = $this->vttToPlainText((string) $raw);
        $text = $this->finalSanitizeTranscript($text);

        $maxChars = (int) config('services.youtube_transcript.yt_dlp_max_chars', 60000);
        if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars);
        }

        $this->cleanupDir($runDir);

        if (trim($text) === '') {
            Log::warning('YoutubeTranscriptService: yt-dlp empty text after parse', [
                'url' => $url,
                'language' => $language,
                'file' => basename($vttPath),
            ]);
            return null;
        }

        return $text;
    }

    protected function findBestSubtitleFile(string $dir): ?string
    {
        $candidates = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.vtt') ?: [];
        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $candidates[0] ?? null;
    }

    protected function vttToPlainText(string $vtt): string
    {
        $vtt = preg_replace("/\xEF\xBB\xBF/", '', $vtt) ?? $vtt;

        $lines = preg_split("/\r\n|\n|\r/", $vtt) ?: [];
        $out = [];
        $prevKey = null;

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || stripos($line, 'WEBVTT') === 0 || stripos($line, 'NOTE') === 0) {
                continue;
            }

            if (preg_match('/^\d+$/', $line)) {
                continue;
            }

            if (strpos($line, '-->') !== false) {
                continue;
            }

            $line = preg_replace('/<[^>]+>/', '', $line) ?? $line;
            $line = preg_replace('/\{\\an\d+\}/', '', $line) ?? $line;

            $line = $this->cleanCaptionChunk($line);
            if ($line === '') {
                continue;
            }

            $key = $this->dedupeKey($line);
            if ($prevKey !== null && $key === $prevKey) {
                continue;
            }

            $prevKey = $key;
            $out[] = $line;
        }

        $text = $this->finalSanitizeTranscript(trim(implode(' ', $out)));

        return $text;
    }

    protected function buildShellCommand(array $args): string
    {
        $escaped = array_map(function ($a) {
            return escapeshellarg((string) $a);
        }, $args);

        return implode(' ', $escaped);
    }

    protected function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->cleanupDir($item);
                @rmdir($item);
                continue;
            }
            @unlink($item);
        }

        @rmdir($dir);
    }

    protected function extractVideoId(string $url): ?string
    {
        $url = trim($url);

        if (preg_match('~youtu\.be/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/watch\?[^#]*v=([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/embed/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/shorts/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/live/([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        $parts = parse_url($url);
        $query = $parts['query'] ?? null;
        if ($query) {
            parse_str($query, $q);
            $v = $q['v'] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    protected function logThrowableChain(Throwable $e, array $context = []): void
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

    protected function cleanCaptionChunk(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim($s);

        // remove common bracket noise like [Music]
        $s = preg_replace('/\[(music|applause|laughter|noise)\]/iu', ' ', $s) ?? $s;

        // collapse whitespace
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    protected function dedupeKey(string $s): string
    {
        $s = mb_strtolower($s);
        // remove punctuation for stable comparison
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    protected function finalSanitizeTranscript(string $text): string
    {
        $text = $this->cleanCaptionChunk($text);

        // remove meta headers if they somehow appear
        $text = preg_replace('/\bKind:\s*captions\b.*?\bLanguage:\s*[a-z-]+\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

}
