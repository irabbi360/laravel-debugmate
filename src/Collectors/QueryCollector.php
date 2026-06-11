<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Illuminate\Support\Facades\DB;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;

/**
 * QueryCollector - Tracks database queries for performance monitoring.
 *
 * This collector:
 * - Records all database queries with execution time
 * - Captures SQL statements and query bindings
 * - Detects slow queries based on configured threshold
 * - Creates spans for each query in the trace
 */
class QueryCollector implements CollectorInterface
{
    protected array $queries = [];
    protected float $slowThreshold = 1000; // ms

    public function __construct(protected PerformanceMonitor $monitor)
    {
        $this->slowThreshold = config('debugmate.thresholds.query_time_ms', 1000);
    }

    public function getName(): string
    {
        return 'queries';
    }

    public function register(): void
    {
        if (!config('debugmate.track_queries', true)) {
            return;
        }

        try {
            DB::listen(function ($queryObj) {
                try {
                    $this->recordQuery($queryObj);
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate QueryCollector: Error recording query: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            \Log::debug('DebugMate QueryCollector: Error registering listener: ' . $e->getMessage());
        }
    }

    /**
     * Record a database query.
     */
    protected function recordQuery($queryObj): void
    {
        $sql = $queryObj->sql ?? '';
        $timeMs = $queryObj->time ?? 0;
        $bindings = $queryObj->bindings ?? [];

        // Extract operation type from SQL
        $operation = $this->extractOperation($sql);

        // Create span for query
        $span = $this->monitor->startSpan('db.query', [
            'db.system' => 'mysql',
            'db.operation' => $operation,
            'db.statement' => $this->sanitizeSql($sql),
            'db.bindings_count' => count($bindings),
            'db.time_ms' => $timeMs,
        ]);

        // Mark as slow if exceeds threshold
        if ($timeMs >= $this->slowThreshold) {
            $span->setAttribute('db.slow_query', true);
            $span->setAttribute('db.threshold_ms', $this->slowThreshold);
        }

        // Record in query list
        $this->queries[] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'time_ms' => $timeMs,
            'operation' => $operation,
            'slow' => $timeMs >= $this->slowThreshold,
        ];

        // Call PerformanceMonitor's recordQuery to add to monitor's queries array
        // This ensures queries are included in the final payload
        $this->monitor->recordQuery($sql, $timeMs, $bindings);

        $this->monitor->endSpan($span);
    }

    /**
     * Extract operation type from SQL statement.
     */
    protected function extractOperation(string $sql): string
    {
        $sql = strtoupper(trim($sql));

        if (str_starts_with($sql, 'SELECT')) {
            return 'SELECT';
        } elseif (str_starts_with($sql, 'INSERT')) {
            return 'INSERT';
        } elseif (str_starts_with($sql, 'UPDATE')) {
            return 'UPDATE';
        } elseif (str_starts_with($sql, 'DELETE')) {
            return 'DELETE';
        } elseif (str_starts_with($sql, 'TRUNCATE')) {
            return 'TRUNCATE';
        } elseif (str_starts_with($sql, 'ALTER')) {
            return 'ALTER';
        } elseif (str_starts_with($sql, 'CREATE')) {
            return 'CREATE';
        } elseif (str_starts_with($sql, 'DROP')) {
            return 'DROP';
        }

        return 'UNKNOWN';
    }

    /**
     * Sanitize SQL for logging (remove potentially sensitive data).
     */
    protected function sanitizeSql(string $sql): string
    {
        // Remove query parameters and truncate if too long
        $maxLength = 500;
        if (strlen($sql) > $maxLength) {
            return substr($sql, 0, $maxLength) . '...';
        }
        return $sql;
    }

    /**
     * Get recorded queries.
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Get slow queries.
     */
    public function getSlowQueries(): array
    {
        return array_filter($this->queries, fn($q) => $q['slow']);
    }

    /**
     * Get query count.
     */
    public function getQueryCount(): int
    {
        return count($this->queries);
    }

    /**
     * Get slow query count.
     */
    public function getSlowQueryCount(): int
    {
        return count($this->getSlowQueries());
    }
}

