<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Support\Facades\Log;
use Irabbi360\LaravelDebugMate\Jobs\ReportMetricJob;

class PerformanceMonitor
{
    protected ApiClient $apiClient;
    protected array $config;
    protected array $timers = [];
    protected array $metrics = [];

    public function __construct(ApiClient $apiClient, array $config)
    {
        $this->apiClient = $apiClient;
        $this->config = $config;
    }

    /**
     * Start monitoring an operation.
     */
    public function startMonitoring(string $name): void
    {
        $this->timers[$name] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];
    }

    /**
     * Stop monitoring and record metric.
     */
    public function stopMonitoring(string $name, array $context = []): float
    {
        if (!isset($this->timers[$name])) {
            Log::warning('No timer found for monitoring: '.$name);
            return 0;
        }

        $timer = $this->timers[$name];
        $duration = (microtime(true) - $timer['start']) * 1000; // Convert to milliseconds
        $memoryUsed = memory_get_usage(true) - $timer['memory_start'];

        $this->recordMetric($name, $duration, array_merge($context, [
            'memory_used_bytes' => $memoryUsed,
        ]));

        unset($this->timers[$name]);

        return $duration;
    }

    /**
     * Record a performance metric.
     */
    public function recordMetric(string $name, float $durationMs, array $context = [], ?string $traceId = null): void
    {
        $traceId = $traceId ?? $this->generateTraceId();

        $metricData = [
            'trace_id' => $traceId,
            'endpoint' => $context['endpoint'] ?? $this->detectEndpoint(),
            'method' => $context['method'] ?? request()->method() ?? null,
            'status_code' => $context['status_code'] ?? null,
            'duration_ms' => $durationMs,
            'memory_usage' => $context['memory_usage'] ?? memory_get_peak_usage(true),
            'threshold_exceeded' => $durationMs > ($this->config['thresholds']['query_time_ms'] ?? 1000),
            'context' => array_merge($this->getDefaultContext(), $context),
        ];

        $this->metrics[$name][] = $metricData;

        if ($this->config['async_reporting']) {
            $this->dispatchAsyncMetric($metricData);
        } else {
            $this->apiClient->reportMetric($metricData);
        }
    }

    /**
     * Record database query performance.
     */
    public function recordQuery(string $query, float $time, array $bindings = [], ?string $traceId = null): void
    {
        $traceId = $traceId ?? $this->generateTraceId();

        $queryData = [
            'trace_id' => $traceId,
            'endpoint' => $this->detectEndpoint(),
            'method' => 'QUERY',
            'duration_ms' => $time,
            'memory_usage' => memory_get_peak_usage(true),
            'threshold_exceeded' => $time > ($this->config['thresholds']['query_time_ms'] ?? 1000),
            'context' => array_merge($this->getDefaultContext(), [
                'query' => $this->sanitizeQuery($query),
                'bindings_count' => count($bindings),
            ]),
        ];

        if ($this->config['async_reporting']) {
            $this->dispatchAsyncMetric($queryData);
        } else {
            $this->apiClient->reportMetric($queryData);
        }
    }

    /**
     * Get metrics summary.
     */
    public function getSummary(): array
    {
        $summary = [];

        foreach ($this->metrics as $name => $metrics) {
            $durations = array_column($metrics, 'duration_ms');
            $summary[$name] = [
                'count' => count($metrics),
                'total_ms' => array_sum($durations),
                'average_ms' => array_sum($durations) / count($metrics),
                'min_ms' => min($durations),
                'max_ms' => max($durations),
            ];
        }

        return $summary;
    }

    /**
     * Clear all metrics.
     */
    public function clearMetrics(): void
    {
        $this->metrics = [];
        $this->timers = [];
    }

    /**
     * Dispatch async metric reporting job.
     */
    protected function dispatchAsyncMetric(array $metricData): void
    {
        try {
            dispatch(new ReportMetricJob($metricData))
                ->onQueue($this->config['queue'] ?? 'default');
        } catch (\Exception $e) {
            Log::error('Failed to dispatch metric reporting job', [
                'error' => $e->getMessage(),
            ]);
            // Fallback to synchronous reporting
            $this->apiClient->reportMetric($metricData);
        }
    }

    /**
     * Sanitize query to remove sensitive data.
     */
    protected function sanitizeQuery(string $query): string
    {
        // Replace sensitive patterns
        $query = preg_replace('/password[\'"]?\s*=\s*[\'"][^\'"]*[\'"]/', 'password = ***', $query);
        $query = preg_replace('/token[\'"]?\s*=\s*[\'"][^\'"]*[\'"]/', 'token = ***', $query);
        $query = preg_replace('/api_key[\'"]?\s*=\s*[\'"][^\'"]*[\'"]/', 'api_key = ***', $query);

        return $query;
    }

    /**
     * Get default context data.
     */
    protected function getDefaultContext(): array
    {
        return array_merge(
            $this->config['context'] ?? [],
            [
                'url' => request()->url() ?? null,
                'method' => request()->method() ?? null,
            ]
        );
    }

    /**
     * Generate unique trace ID.
     */
    protected function generateTraceId(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }

    /**
     * Detect endpoint from request URL.
     */
    protected function detectEndpoint(): ?string
    {
        try {
            $url = request()->url();
            return parse_url($url, PHP_URL_PATH);
        } catch (\Exception $e) {
            return null;
        }
    }
}

