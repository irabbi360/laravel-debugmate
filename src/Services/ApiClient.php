<?php

namespace Irabbi360\LaravelDebugMate\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

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
     * Verify API connection.
     */
    public function verifyConnection(): bool
    {
        try {
            $response = $this->client->get('/api/debugmate/verify', [
                'headers' => $this->getHeaders(),
            ]);

            return $response->getStatusCode() === 200;
        } catch (RequestException $e) {
            Log::error('DebugMate connection verification failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return false;
        }
    }

    /**
     * Get project info.
     */
    public function getProjectInfo(): ?array
    {
        try {
            $response = $this->client->get('/api/debugmate/projects/'.$this->debugmateKey, [
                'headers' => $this->getHeaders(),
            ]);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->getContents(), true);
            }
        } catch (RequestException $e) {
            Log::error('DebugMate project info fetch failed', [
                'error' => $e->getMessage(),
                'debugmate_key' => $this->debugmateKey,
            ]);
        }

        return null;
    }

    /**
     * Send data to API endpoint.
     */
    protected function send(string $endpoint, array $data): bool
    {
        // Check if key is configured
        if ($this->debugmateKey === 'not-configured') {
            Log::warning('DebugMate key is not configured. Set DEBUGMATE_PROJECT_KEY in .env', [
                'endpoint' => $endpoint,
            ]);
            return false;
        }

        try {
            $payload = array_merge($data, [
                'timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
                'environment' => config('app.env', 'production'),
            ]);

            $response = $this->client->post($endpoint, [
                'headers' => $this->getHeaders(),
                'json' => $payload,
            ]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return true;
            }

            Log::warning('DebugMate API returned non-success status', [
                'status_code' => $response->getStatusCode(),
                'endpoint' => $endpoint,
            ]);

            return false;
        } catch (RequestException $e) {
            Log::error('DebugMate API request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return false;
        }
    }

    /**
     * Get request headers.
     */
    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'DebugMate-SDK/2.0',
            'X-DebugMate-Key' => $this->debugmateKey,
        ];
    }
}

