<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Support\InternalLog;

/**
 * QueryCollector - Tracks database queries for performance monitoring.
 *
 * This collector:
 * - Records all database queries with execution time
 * - Captures SQL statements and query bindings
 * - Detects slow queries based on configured threshold
 * - Creates spans for each query in the trace
 *
 * Recorded queries live on the PerformanceMonitor, which resets them on every
 * trace start and flush. The collector deliberately keeps no copy of its own:
 * DB::listen is registered once per process and the collector is never told
 * when a request or job ends, so any buffer it owned would grow for the whole
 * process lifetime.
 */
class QueryCollector implements CollectorInterface
{
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
                    InternalLog::debug('DebugMate QueryCollector: Error recording query: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            InternalLog::debug('DebugMate QueryCollector: Error registering listener: ' . $e->getMessage());
        }
    }

    /**
     * Record a database query.
     */
    protected function recordQuery($queryObj): void
    {
        $sql = $queryObj->sql ?? '';

        // No active trace means nothing will ever flush this span. Recording it
        // would leak: a queue worker polling the `jobs` table emits a query per
        // second between jobs, forever.
        if (! $this->monitor->hasActiveTrace()) {
            return;
        }

        // Never trace writes to telemetry or queue infrastructure - doing so
        // makes reporting generate more to report.
        if ($this->shouldIgnoreQuery($sql)) {
            return;
        }

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

        // Persist query data for the final performance payload
        $this->monitor->appendQuery($sql, $timeMs, $bindings);

        $this->monitor->endSpan($span);
    }

    /**
     * Whether this SQL touches a table excluded from tracking.
     */
    public function shouldIgnoreQuery(string $sql): bool
    {
        $patterns = (array) config('debugmate.ignore_tables', []);

        if (empty($patterns)) {
            return false;
        }

        foreach ($this->extractTables($sql) as $table) {
            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $table)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract the table names referenced by a SQL statement.
     *
     * @return array<int, string>
     */
    protected function extractTables(string $sql): array
    {
        // One identifier, optionally quoted with backticks, double quotes or
        // brackets. `table` covers CREATE/ALTER/TRUNCATE TABLE.
        $identifier = '(?:["`\[]?[a-zA-Z0-9_$]+["`\]]?)';

        $matched = preg_match_all(
            '/\b(?:from|into|update|join|table)\s+((?:'.$identifier.'\s*\.\s*)*'.$identifier.')/i',
            $sql,
            $matches
        );

        if (! $matched) {
            return [];
        }

        return array_map(static function (string $table): string {
            // Drop any schema/database qualifier: `mydb`.`jobs` -> jobs
            $parts = explode('.', $table);

            return strtolower(trim((string) end($parts), " \t\n\r\0\x0B\"`[]"));
        }, $matches[1]);
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
        $sql = \Irabbi360\LaravelDebugMate\Support\Sanitizer::instance()->sanitizeQuery($sql);

        // Truncate if too long
        $maxLength = 500;
        if (strlen($sql) > $maxLength) {
            return substr($sql, 0, $maxLength) . '...';
        }
        return $sql;
    }

    /**
     * Get recorded queries for the current trace.
     */
    public function getQueries(): array
    {
        return $this->monitor->getQueries();
    }

    /**
     * Get slow queries for the current trace.
     */
    public function getSlowQueries(): array
    {
        return $this->monitor->getSlowQueries();
    }

    /**
     * Get query count for the current trace.
     */
    public function getQueryCount(): int
    {
        return $this->monitor->getQueryCount();
    }

    /**
     * Get slow query count for the current trace.
     */
    public function getSlowQueryCount(): int
    {
        return count($this->getSlowQueries());
    }
}
