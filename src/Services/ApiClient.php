<?php

namespace Irabbi360\LaravelDebugMate\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Irabbi360\LaravelDebugMate\Support\InternalLog;
use Irabbi360\LaravelDebugMate\Support\PayloadLimiter;

class ApiClient
{
    protected Client $client;
    protected string $apiUrl;
    protected string $debugmateKey;

    protected static bool $insecureWarningLogged = false;

    public function __construct(string $apiUrl, string $debugmateKey)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->debugmateKey = $debugmateKey;

        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'timeout' => config('debugmate.request_timeout', 10),
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
     * Send data to API endpoint.
     */
    protected function send(string $endpoint, array $data): bool
    {
        if (! $this->hasConfiguredKey()) {
            InternalLog::warning('DebugMate key is not configured. Set DEBUGMATE_PROJECT_KEY in .env', [
                'endpoint' => $endpoint,
            ]);

            return false;
        }

        $this->warnIfInsecureTransport();

        try {
            $payload = array_merge($data, [
                'timestamp' => $this->resolveEventTimestamp($data['timestamp'] ?? null),
                'environment' => $data['environment'] ?? config('app.env', 'production'),
            ]);

            $payload = (new PayloadLimiter)->fit($payload);

            $response = $this->client->post($endpoint, [
                'headers' => $this->getHeaders(),
                'json' => $payload,
            ]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return true;
            }

            InternalLog::warning('DebugMate API returned non-success status', [
                'status_code' => $response->getStatusCode(),
                'endpoint' => $endpoint,
            ]);

            return false;
        } catch (\Throwable $e) {
            // Any failure (connection refused, DNS, timeout, bad response,
            // JSON encoding) must never break the host application.
            InternalLog::error('DebugMate API request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return false;
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
            return \Carbon\Carbon::parse($timestamp)->format($format);
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

