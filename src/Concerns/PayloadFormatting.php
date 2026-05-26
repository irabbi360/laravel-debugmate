<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

/**
 * PayloadFormatting - Trait for formatting data payloads for API transmission
 *
 * Normalizes data structure, handles encoding, and prepares for transmission.
 */
trait PayloadFormatting
{
    /**
     * Format error payload for API.
     */
    public function formatErrorPayload(array $errorData): array
    {
        return [
            'trace_id' => $errorData['trace_id'] ?? null,
            'error_type' => $errorData['error_type'] ?? 'Exception',
            'message' => $errorData['message'] ?? 'Unknown error',
            'file' => $errorData['file'] ?? null,
            'line' => $errorData['line'] ?? 0,
            'code' => $errorData['code'] ?? 0,
            'stack_trace' => $errorData['stack_trace'] ?? null,
            'frames' => $errorData['frames'] ?? [],
            'fingerprint' => $errorData['fingerprint'] ?? null,
            'context' => $errorData['context'] ?? [],
            'source' => $errorData['source'] ?? 'http',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Format log payload for API.
     */
    public function formatLogPayload(array $logData): array
    {
        return [
            'trace_id' => $logData['trace_id'] ?? null,
            'message' => $logData['message'] ?? '',
            'level' => $logData['level'] ?? 'info',
            'channel' => $logData['channel'] ?? 'default',
            'context' => $logData['context'] ?? [],
            'source' => $logData['source'] ?? 'http',
            'stack_trace' => $logData['stack_trace'] ?? null,
            'exception_class' => $logData['exception_class'] ?? null,
            'file' => $logData['file'] ?? null,
            'line' => $logData['line'] ?? null,
            'user_id' => $logData['user_id'] ?? null,
            'url' => $logData['url'] ?? null,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Format metric payload for API.
     */
    public function formatMetricPayload(array $metricData): array
    {
        return [
            'trace_id' => $metricData['trace_id'] ?? null,
            'endpoint' => $metricData['endpoint'] ?? '/',
            'method' => $metricData['method'] ?? 'GET',
            'status_code' => $metricData['status_code'] ?? 200,
            'duration_ms' => round($metricData['duration_ms'] ?? 0, 3),
            'memory_usage' => $metricData['memory_usage'] ?? null,
            'query_count' => $metricData['query_count'] ?? 0,
            'slow_query_count' => $metricData['slow_query_count'] ?? 0,
            'slow_queries' => $metricData['slow_queries'] ?? [],
            'all_queries' => $metricData['all_queries'] ?? [],
            'spans' => $metricData['spans'] ?? [],
            'context' => $metricData['context'] ?? [],
            'environment' => $metricData['environment'] ?? config('app.env'),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Format analytics payload for API.
     */
    public function formatAnalyticsPayload(array $analyticsData): array
    {
        return [
            'session_id' => $analyticsData['session_id'] ?? null,
            'type' => $analyticsData['type'] ?? 'page_view',
            'project_key' => $analyticsData['project_key'] ?? null,
            'user_id' => $analyticsData['user_id'] ?? null,
            'user_fingerprint' => $analyticsData['user_fingerprint'] ?? null,
            'device_type' => $analyticsData['device_type'] ?? null,
            'browser' => $analyticsData['browser'] ?? null,
            'os' => $analyticsData['os'] ?? null,
            'country' => $analyticsData['country'] ?? null,
            'country_code' => $analyticsData['country_code'] ?? null,
            'page_url' => $analyticsData['page_url'] ?? null,
            'page_title' => $analyticsData['page_title'] ?? null,
            'page_views' => $analyticsData['page_views'] ?? 0,
            'bounce_count' => $analyticsData['bounce_count'] ?? 0,
            'session_duration_seconds' => $analyticsData['session_duration_seconds'] ?? 0,
            'load_time_ms' => $analyticsData['load_time_ms'] ?? null,
            'event_name' => $analyticsData['event_name'] ?? null,
            'event_data' => $analyticsData['event_data'] ?? [],
            'context' => $analyticsData['context'] ?? [],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Encode payload to JSON.
     */
    public function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validate payload structure.
     */
    public function validatePayload(array $payload, string $type = 'error'): bool
    {
        $requiredFields = $this->getRequiredFields($type);

        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get required fields for payload type.
     */
    protected function getRequiredFields(string $type): array
    {
        return match($type) {
            'error' => ['trace_id', 'error_type', 'message', 'file', 'line'],
            'log' => ['message', 'level'],
            'metric' => ['trace_id', 'endpoint', 'method', 'duration_ms'],
            'analytics' => ['session_id', 'type', 'page_url'],
            default => [],
        };
    }

    /**
     * Compress payload for transmission.
     */
    public function compressPayload(array $payload): string
    {
        $json = $this->encodePayload($payload);
        return gzcompress($json);
    }

    /**
     * Add metadata to payload.
     */
    public function addMetadata(array $payload): array
    {
        return array_merge($payload, [
            'app_version' => config('app.version', '1.0.0'),
            'package_version' => $this->getPackageVersion(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ]);
    }

    /**
     * Get package version.
     */
    protected function getPackageVersion(): string
    {
        return '2.0.0';
    }
}

