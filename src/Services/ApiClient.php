<?php

namespace Irabbi360\LaravelDebugMate\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Irabbi360\LaravelDebugMate\Support\InternalLog;
use Irabbi360\LaravelDebugMate\Support\PayloadLimiter;

class ApiClient
{
    protected Client $client;
    protected string $apiUrl;
    protected string $debugmateKey;

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

            if (in_array($status, [401, 403], true)) {
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
            } catch (RequestException $fallback) {
                InternalLog::error('DebugMate connection verification failed', [
                    'error' => $fallback->getMessage(),
                    'code' => $fallback->getCode(),
                ]);

                return false;
            }
        }
    }

    /**
     * Get project info.
     */
    public function getProjectInfo(): ?array
    {
        try {
            $response = $this->client->get('/api/debugmate/project', [
                'headers' => $this->getHeaders(),
            ]);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }
        } catch (RequestException $e) {
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
        } catch (RequestException $e) {
            InternalLog::error('DebugMate API request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return false;
        }
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

