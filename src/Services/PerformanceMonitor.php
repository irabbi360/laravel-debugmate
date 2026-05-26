<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Support\Facades\Log;
use Irabbi360\LaravelDebugMate\Jobs\ReportMetricJob;
use Irabbi360\LaravelDebugMate\Tracing\Span;
use Irabbi360\LaravelDebugMate\Tracing\Tracer;
use Irabbi360\LaravelDebugMate\DTO\MetricDataDTO;
use Irabbi360\LaravelDebugMate\DTO\QueryDataDTO;
use Irabbi360\LaravelDebugMate\Concerns\CaptureQueryData;
use Irabbi360\LaravelDebugMate\Concerns\CaptureRequestData;
use Irabbi360\LaravelDebugMate\Concerns\AsyncDispatch;

class PerformanceMonitor
{
    use CaptureQueryData;
    use CaptureRequestData;
    use AsyncDispatch;

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
     * End request and flush ONE payload with all spans using DTO.
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

        // Create metric DTO
        $metricData = MetricDataDTO::from([
            'trace_id' => $this->tracer->getTraceId(),
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'memory_usage' => memory_get_peak_usage(true),
            'threshold_exceeded' => $durationMs > $responseThreshold,
            'environment' => config('app.env', 'production'),
            'query_count' => count($this->queries),
            'slow_query_count' => count($slowQueries),
            'slow_queries' => $slowQueries,
            'all_queries' => $this->queries,
            'spans' => $allSpans['spans'] ?? [],
            'context' => [
                'app_name' => config('app.name'),
            ],
        ]);

        $this->reportFromDTO($metricData);

        $this->tracer = new Tracer();
        $this->rootSpan = null;
        $this->queries = [];
    }

    /**
     * Report from MetricDataDTO.
     */
    public function reportFromDTO(MetricDataDTO $dto): void
    {
        $data = $dto->toArray();

        if ($this->config['send_performance_to_api'] ?? true) {
            $this->sendPayload($data);
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
     * Record database query as a span using DTO.
     */
    public function recordQuery(string $sql, float $timeMs, array $bindings = []): void
    {
        if (!($this->config['track_queries'] ?? false)) {
            return;
        }

        // Create query DTO
        $queryDto = QueryDataDTO::from([
            'sql' => $sql,
            'time_ms' => $timeMs,
            'bindings' => $bindings,
            'bindings_count' => count($bindings),
            'database' => 'default',
        ]);

        $span = $this->startSpan('db.query', [
            'db.system' => 'mysql',
            'db.operation' => $queryDto->operation,
            'db.statement' => $queryDto->sql,
            'db.bindings_count' => $queryDto->bindings_count,
        ]);

        $span->setAttribute('duration_ms', $queryDto->time_ms);

        $this->queries[] = $queryDto->toArray();

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
                $this->dispatchMetricReport($payload);
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

    protected function getSlowQueryThreshold(): ?float
    {
        return $this->config['thresholds']['query_time_ms'] ?? 1000;
    }
}

