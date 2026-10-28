<?php

namespace Irabbi360\LaravelDebugMate\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Exception\RequestException;
use Irabbi360\LaravelDebugMate\Support\InternalLog;
use Irabbi360\LaravelDebugMate\Support\PayloadLimiter;
use Irabbi360\LaravelDebugMate\Support\ReportBuffer;

class ApiClient
{
    protected Client $client;
    protected string $apiUrl;
    protected string $debugmateKey;

    protected static bool $insecureWarningLogged = false;

    /** Circuit breaker: unix time until which the API is considered down (this process). */
    protected static float $circuitOpenUntil = 0.0;

    protected const CIRCUIT_CACHE_KEY = 'debugmate:api_circuit_open_until';

    public function __construct(string $apiUrl, string $debugmateKey, ?Client $client = null)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->debugmateKey = $debugmateKey;

        $this->client = $client ?? new Client([
            'base_uri' => $this->apiUrl,
            'timeout' => (float) config('debugmate.request_timeout', 5),
            'connect_timeout' => (float) config('debugmate.connect_timeout', 2),
            // Never follow redirects: Guzzle strips only Authorization/Cookie on
            // cross-origin redirects, so X-DebugMate-Key would be forwarded to
            // the redirect target (and https -> http downgrades are allowed).
            'allow_redirects' => false,
            'verify' => true,
        ]);
    }

    /**
     * Send error report to API.
     */
    public function reportError(array $errorData): bool
    {
        return $this->send('/api/debugmate/errors', $errorData);
    }

    /**
     * Send performance metrics to API.
     */
    public function reportMetric(array $metricData): bool
    {
        return $this->send('/api/debugmate/metrics', $metricData);
    }

    /**
     * Send log to API.
     */
    public function reportLog(array $logData): bool
    {
        return $this->send('/api/debugmate/logs', $logData);
    }

    /**
     * Send query performance to API.
     */
    public function reportQuery(array $queryData): bool
    {
        return $this->send('/api/debugmate/queries', $queryData);
    }

    /**
     * Send analytics data to API.
     */
    public function reportAnalytics(array $analyticsData): bool
    {
        return $this->send('/api/debugmate/analytics', $analyticsData);
    }

    /**
     * Register / sync uptime & SSL monitor targets with DebugMate.
     */
    public function registerMonitors(array $payload): bool
    {
        return $this->send('/api/debugmate/monitors', $payload);
    }

    /**
     * Verify API connection using POST so ingest auth is tested, not only GET.
     */
    public function verifyConnection(): bool
    {
        if (! $this->hasConfiguredKey()) {
            InternalLog::warning('DebugMate key is not configured. Set DEBUGMATE_PROJECT_KEY in .env');

            return false;
        }

        try {
            $response = $this->client->post('/api/debugmate/verify-token', [
                'headers' => $this->getHeaders(),
                'json' => [],
            ]);

            return $response->getStatusCode() === 200;
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();

            // No response (DNS, refused, TLS) or an auth rejection: falling back won't help.
            if ($status === null || in_array($status, [401, 403], true)) {
                InternalLog::error('DebugMate connection verification failed', [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);

                return false;
            }
        } catch (GuzzleException $e) {
            InternalLog::error('DebugMate connection verification failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return false;
        }

        // Older APIs only expose GET verify-token.
        try {
            $response = $this->client->get('/api/debugmate/verify-token', [
                'headers' => $this->getHeaders(),
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $fallback) {
            InternalLog::error('DebugMate connection verification failed', [
                'error' => $fallback->getMessage(),
                'code' => $fallback->getCode(),
            ]);

            return false;
        }
    }

    /**
     * Get project info.
     */
    public function getProjectInfo(): ?array
    {
        if (! $this->hasConfiguredKey()) {
            return null;
        }

        try {
            $response = $this->client->get('/api/debugmate/project', [
                'headers' => $this->getHeaders(),
            ]);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }
        } catch (\Throwable $e) {
            InternalLog::error('DebugMate project info fetch failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Reports that may wait for the end of a web request, keyed by endpoint.
     */
    protected const DEFERRABLE_ENDPOINTS = [
        '/api/debugmate/errors' => 'errors',
        '/api/debugmate/metrics' => 'metrics',
        '/api/debugmate/logs' => 'logs',
        '/api/debugmate/queries' => 'queries',
        '/api/debugmate/analytics' => 'analytics',
    ];

    protected const BATCH_ENDPOINT = '/api/debugmate/batch';

    /** Most items the API accepts in one batch request. */
    protected const BATCH_MAX_ITEMS = 100;

    /**
     * Send data to API endpoint - or, during a web request, hold it for the
     * batch sent after the response so the visitor never waits on DebugMate.
     */
    protected function send(string $endpoint, array $data): bool
    {
        try {
            $payload = $this->preparePayload($data);
        } catch (\Throwable $e) {
            InternalLog::error('DebugMate payload could not be prepared', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (isset(self::DEFERRABLE_ENDPOINTS[$endpoint]) && ReportBuffer::shouldDefer()) {
            ReportBuffer::push(self::DEFERRABLE_ENDPOINTS[$endpoint], $payload);

            return true;
        }

        return $this->isSuccessful($this->post($endpoint, $payload));
    }

    /**
     * Send everything buffered during this request in as few calls as possible.
     * Runs from app()->terminating(), i.e. after the response has been sent.
     */
    public function flushDeferred(): void
    {
        ['items' => $items, 'dropped' => $dropped] = ReportBuffer::drain();

        if ($dropped > 0) {
            InternalLog::warning('DebugMate buffer limit reached; some reports from this request were dropped', [
                'dropped' => $dropped,
                'buffer_limit' => (int) config('debugmate.buffer_limit', 200),
            ]);
        }

        foreach ($this->chunkBatch($items) as $chunk) {
            // Older DebugMate servers have no batch endpoint: send the items one by one.
            if ($this->post(self::BATCH_ENDPOINT, ['items' => $chunk]) === 404) {
                foreach ($chunk as $item) {
                    $this->post((string) array_search($item['type'], self::DEFERRABLE_ENDPOINTS, true), $item['payload']);
                }
            }
        }
    }

    /**
     * @param  list<array{type: string, payload: array}>  $items
     * @return list<list<array{type: string, payload: array}>>
     */
    protected function chunkBatch(array $items): array
    {
        $maxBytes = max(1, (int) config('debugmate.max_batch_size', 1048576));
        $chunks = [];
        $chunk = [];
        $chunkBytes = 0;

        foreach ($items as $item) {
            $itemBytes = strlen(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');

            if ($chunk !== [] && (count($chunk) >= self::BATCH_MAX_ITEMS || $chunkBytes + $itemBytes > $maxBytes)) {
                $chunks[] = $chunk;
                $chunk = [];
                $chunkBytes = 0;
            }

            $chunk[] = $item;
            $chunkBytes += $itemBytes;
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    protected function preparePayload(array $data): array
    {
        $payload = array_merge($data, [
            'timestamp' => $this->resolveEventTimestamp($data['timestamp'] ?? null),
            'environment' => $data['environment'] ?? config('app.env', 'production'),
        ]);

        return (new PayloadLimiter)->fit($payload);
    }

    protected function isSuccessful(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * POST a prepared payload. Returns the HTTP status, or 0 when nothing was sent / no response.
     */
    protected function post(string $endpoint, array $payload): int
    {
        if (! $this->hasConfiguredKey()) {
            InternalLog::warning('DebugMate key is not configured. Set DEBUGMATE_PROJECT_KEY in .env', [
                'endpoint' => $endpoint,
            ]);

            return 0;
        }

        if ($this->apiUrl === '') {
            InternalLog::warning('DebugMate API URL is not configured. Set DEBUGMATE_API_URL in .env', [
                'endpoint' => $endpoint,
            ]);

            return 0;
        }

        // The API recently failed to connect: fail fast instead of making
        // every request wait for another timeout.
        if ($this->circuitIsOpen()) {
            return 0;
        }

        $this->warnIfInsecureTransport();

        try {
            $response = $this->client->post($endpoint, [
                'headers' => $this->getHeaders(),
                'json' => $payload,
            ]);

            $status = $response->getStatusCode();

            if (! $this->isSuccessful($status)) {
                $this->logNonSuccess($endpoint, $status);
            }

            return $status;
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();

            if ($status !== null) {
                // A 404 from the batch endpoint just means an older server; the caller falls back.
                if (! ($status === 404 && $endpoint === self::BATCH_ENDPOINT)) {
                    $this->logNonSuccess($endpoint, $status);
                }

                return $status;
            }

            return $this->failed($endpoint, $e);
        } catch (\Throwable $e) {
            return $this->failed($endpoint, $e);
        }
    }

    protected function logNonSuccess(string $endpoint, int $status): void
    {
        InternalLog::warning('DebugMate API returned non-success status', array_filter([
            'status_code' => $status,
            'endpoint' => $endpoint,
            'hint' => $status >= 300 && $status < 400
                ? 'Redirects are not followed (they could leak the project key). Point DEBUGMATE_API_URL at the final URL, e.g. https:// instead of http://.'
                : null,
        ]));
    }

    /**
     * Any failure (connection refused, DNS, timeout, bad response, JSON
     * encoding) must never break the host application.
     */
    protected function failed(string $endpoint, \Throwable $e): int
    {
        if ($e instanceof ConnectException) {
            $this->openCircuit();
        }

        InternalLog::error('DebugMate API request failed', [
            'endpoint' => $endpoint,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);

        return 0;
    }

    protected function circuitIsOpen(): bool
    {
        $now = microtime(true);

        if (static::$circuitOpenUntil > $now) {
            return true;
        }

        try {
            $sharedUntil = (float) Cache::get(self::CIRCUIT_CACHE_KEY, 0);
        } catch (\Throwable) {
            return false;
        }

        if ($sharedUntil > $now) {
            static::$circuitOpenUntil = $sharedUntil;

            return true;
        }

        return false;
    }

    /**
     * Stop calling the API for a while; shared through the cache so every
     * PHP-FPM worker / queue worker backs off together.
     */
    protected function openCircuit(): void
    {
        $seconds = (int) config('debugmate.circuit_breaker_seconds', 30);

        if ($seconds <= 0) {
            return;
        }

        static::$circuitOpenUntil = microtime(true) + $seconds;

        try {
            Cache::put(self::CIRCUIT_CACHE_KEY, static::$circuitOpenUntil, $seconds);
        } catch (\Throwable) {
            // cache unavailable - the per-process breaker still applies
        }
    }

    /**
     * Close the circuit (used by tests and after configuration changes).
     */
    public static function resetCircuit(): void
    {
        static::$circuitOpenUntil = 0.0;

        try {
            Cache::forget(self::CIRCUIT_CACHE_KEY);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * The project key is sent on every request; warn (once per process) when
     * it would travel in clear text to a non-local host.
     */
    protected function warnIfInsecureTransport(): void
    {
        if (static::$insecureWarningLogged || ! $this->isInsecureRemoteUrl()) {
            return;
        }

        static::$insecureWarningLogged = true;

        InternalLog::warning('DebugMate API URL uses plain HTTP; the project key is sent unencrypted. Use https:// for DEBUGMATE_API_URL.', [
            'api_url' => $this->apiUrl,
        ]);
    }

    public function isInsecureRemoteUrl(): bool
    {
        return static::isInsecureUrl($this->apiUrl);
    }

    /**
     * True for plain-HTTP URLs that point anywhere other than this machine.
     */
    public static function isInsecureUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'http') {
            return false;
        }

        return ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.localhost');
    }

    /**
     * Prefer event occurrence time from payload; fall back to send time.
     * Normalized to API format: Y-m-d\TH:i:s\Z
     */
    protected function resolveEventTimestamp(mixed $timestamp): string
    {
        $format = 'Y-m-d\TH:i:s\Z';

        if ($timestamp === null || $timestamp === '') {
            return now()->format($format);
        }

        if (is_string($timestamp) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $timestamp)) {
            return $timestamp;
        }

        try {
            return \Carbon\Carbon::parse($timestamp)->utc()->format($format);
        } catch (\Throwable) {
            return now()->format($format);
        }
    }

    /**
     * Get request headers.
     */
    protected function getHeaders(): array
    {
        $key = $this->resolveKey();

        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'DebugMate-SDK/1.0',
            'X-DebugMate-Key' => $key,
            'Authorization' => 'Bearer '.$key,
        ];
    }

    protected function resolveKey(): string
    {
        $key = trim((string) (config('debugmate.debugmate_key') ?: $this->debugmateKey));

        return $key !== '' ? $key : 'not-configured';
    }

    protected function hasConfiguredKey(): bool
    {
        $key = $this->resolveKey();

        return $key !== '' && $key !== 'not-configured';
    }
}

