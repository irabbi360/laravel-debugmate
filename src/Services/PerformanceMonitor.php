<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Support\Facades\Log;
use Irabbi360\LaravelDebugMate\Jobs\ReportMetricJob;
use Irabbi360\LaravelDebugMate\Tracing\Span;
use Irabbi360\LaravelDebugMate\Tracing\Tracer;

class PerformanceMonitor
{
    protected ApiClient $apiClient;
    protected array $config;
    protected Tracer $tracer;
    protected ?Span $rootSpan = null;
    protected array $queries = [];

    public function __construct(ApiClient $apiClient, array $config)
    {
        $this->apiClient = $apiClient;
        $this->config = $config;
        $this->tracer = new Tracer();
    }

    /**
     * Start tracing a request with OpenTelemetry.
     */
    public function startRequest(?string $traceParentHeader = null): string
    {
        if ($traceParentHeader) {
            $fromParent = Tracer::fromTraceParent($traceParentHeader);
            if ($fromParent) {
                $this->tracer = $fromParent;
            }
        }

        $this->rootSpan = $this->tracer->startSpan('http.request', [
            'http.method' => request()->method(),
            'http.url' => request()->url(),
            'http.target' => request()->path(),
        ]);

        $this->queries = [];
        return $this->tracer->getTraceId();
    }

    /**
     * End request and flush ONE payload with all spans.
     */
    public function flushRequest(string $endpoint, string $method, int $statusCode): void
    {
        if ($this->rootSpan === null) {
            return;
        }

        $this->rootSpan->end();
        $this->rootSpan->setAttribute('http.status_code', $statusCode);

        $responseThreshold = $this->config['thresholds']['response_time_ms'] ?? 5000;
        $durationMs = $this->rootSpan->getDurationMs();

        $queryThreshold = $this->config['thresholds']['query_time_ms'] ?? 1000;
        $slowQueries = array_values(array_filter($this->queries, fn($q) => $q['time_ms'] >= $queryThreshold));

        $allSpans = $this->tracer->toArray();

        $payload = [
            'trace_id' => $this->tracer->getTraceId(),
            'trace_flags' => $this->tracer->getTraceFlags(),
            'is_sampled' => $this->tracer->isSampled(),
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $statusCode,
            'duration_ms' => round($durationMs, 3),
            'memory_usage' => memory_get_peak_usage(true),
            'threshold_exceeded' => $durationMs > $responseThreshold,
            'environment' => config('app.env', 'production'),
            'context' => [
                'app_name' => config('app.name'),
                'query_count' => count($this->queries),
                'slow_query_count' => count($slowQueries),
                'slow_queries' => $slowQueries,
                'spans' => $allSpans['spans'] ?? [],
            ],
        ];

        $this->tracer = new Tracer();
        $this->rootSpan = null;
        $this->queries = [];

        if ($this->config['send_performance_to_api'] ?? true) {
            $this->sendPayload($payload);
        }
    }

    /**
     * Start an OpenTelemetry span (nested operation).
     */
    public function startSpan(string $name, array $attributes = []): Span
    {
        return $this->tracer->startSpan($name, $attributes);
    }

    /**
     * End a span.
     */
    public function endSpan(?Span $span = null): ?Span
    {
        return $this->tracer->endSpan($span);
    }

    /**
     * Time a callable block (start → execute → end span).
     */
    public function timeSpan(string $name, callable $callback, array $attributes = []): mixed
    {
        $span = $this->startSpan($name, $attributes);
        try {
            $result = $callback($span);
            $span->setStatus(Span::STATUS_OK);
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            throw $e;
        } finally {
            $this->endSpan($span);
        }
    }

    /**
     * Backward compatibility methods.
     */
    public function startMonitoring(string $name): void
    {
        $this->startSpan($name);
    }

    public function stopMonitoring(string $name, array $context = []): float
    {
        $span = $this->tracer->getCurrentSpan();
        if ($span && $span->getName() === $name) {
            $this->endSpan($span);
            return $span->getDurationMs();
        }
        return 0.0;
    }

    /**
     * Record database query as a span.
     */
    public function recordQuery(string $sql, float $timeMs, array $bindings = []): void
    {
        if (!($this->config['track_queries'] ?? false)) {
            return;
        }

        $span = $this->startSpan('db.query', [
            'db.system' => 'mysql',
            'db.operation' => $this->parseQueryOperation($sql),
            'db.statement' => $this->sanitizeQuery($sql),
            'db.bindings_count' => count($bindings),
        ]);

        $span->setAttribute('duration_ms', round($timeMs, 3));

        $this->queries[] = [
            'sql' => $this->sanitizeQuery($sql),
            'time_ms' => round($timeMs, 3),
            'bindings' => count($bindings),
        ];

        $this->endSpan($span);
    }

    /**
     * Record a manual metric.
     */
    public function recordMetric(string $name, float $durationMs, array $context = [], ?string $traceId = null): void
    {
        if (!($this->config['send_performance_to_api'] ?? true)) {
            return;
        }

        $threshold = $this->config['thresholds']['response_time_ms'] ?? 5000;
        $payload = [
            'trace_id' => $traceId ?? $this->tracer->getTraceId(),
            'endpoint' => $context['endpoint'] ?? $name,
            'method' => $context['method'] ?? 'CLI',
            'status_code' => $context['status_code'] ?? null,
            'duration_ms' => round($durationMs, 3),
            'memory_usage' => $context['memory_usage'] ?? memory_get_peak_usage(true),
            'threshold_exceeded' => $durationMs > $threshold,
            'environment' => config('app.env', 'production'),
            'context' => array_merge(['app_name' => config('app.name')], $context),
        ];

        $this->sendPayload($payload);
    }

    /**
     * Get W3C Trace Context header (for distributed tracing).
     */
    public function getTraceParentHeader(): string
    {
        return $this->tracer->getTraceParent();
    }

    /**
     * Get trace context for propagation to jobs.
     */
    public function getTraceContext(): array
    {
        return [
            'trace_id' => $this->tracer->getTraceId(),
            'trace_flags' => $this->tracer->getTraceFlags(),
            'trace_parent' => $this->tracer->getTraceParent(),
        ];
    }

    // ── Accessors ────────────────────────────────────────────────────────

    public function getTraceId(): string
    {
        return $this->tracer->getTraceId();
    }

    public function getTracer(): Tracer
    {
        return $this->tracer;
    }

    public function getQueryCount(): int
    {
        return count($this->queries);
    }

    public function getSummary(): array
    {
        $spans = $this->tracer->getSpans();
        $durations = array_map(fn($s) => $s->getDurationMs(), $spans);

        return [
            'trace_id' => $this->tracer->getTraceId(),
            'query_count' => count($this->queries),
            'span_count' => count($spans),
            'total_duration_ms' => $durations ? array_sum($durations) : 0,
            'spans' => array_map(fn($s) => $s->toArray(), $spans),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    protected function sendPayload(array $payload): void
    {
        $async = $this->config['async_reporting'] ?? false;

        if ($async) {
            try {
                dispatch(new ReportMetricJob($payload))->onQueue('default');
                return;
            } catch (\Throwable $e) {
                Log::error('DebugMate: Failed to dispatch metric job', ['error' => $e->getMessage()]);
            }
        }

        try {
            $this->apiClient->reportMetric($payload);
        } catch (\Throwable $e) {
            Log::error('DebugMate: Failed to send metric', ['error' => $e->getMessage()]);
        }
    }

    protected function sanitizeQuery(string $query): string
    {
        $query = preg_replace('/password\s*=\s*[\'"][^\'"]*[\'"]/', 'password = ***', $query);
        $query = preg_replace('/token\s*=\s*[\'"][^\'"]*[\'"]/', 'token = ***', $query);
        $query = preg_replace('/api_key\s*=\s*[\'"][^\'"]*[\'"]/', 'api_key = ***', $query);
        return $query;
    }

    protected function parseQueryOperation(string $sql): string
    {
        if (preg_match('/^\s*SELECT/i', $sql)) return 'SELECT';
        if (preg_match('/^\s*INSERT/i', $sql)) return 'INSERT';
        if (preg_match('/^\s*UPDATE/i', $sql)) return 'UPDATE';
        if (preg_match('/^\s*DELETE/i', $sql)) return 'DELETE';
        return 'QUERY';
    }
}

