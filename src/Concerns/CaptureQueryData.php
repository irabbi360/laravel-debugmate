<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

/**
 * CaptureQueryData - Trait for capturing database query information
 *
 * Extracts query details, performance metrics, and slow query detection.
 */
trait CaptureQueryData
{
    /**
     * Capture query information.
     */
    public function captureQueryData(string $sql, float $timeMs, array $bindings = []): array
    {
        return [
            'sql' => $sql,
            'sql_sanitized' => $this->sanitizeQuery($sql),
            'time_ms' => round($timeMs, 3),
            'bindings' => $bindings,
            'bindings_count' => count($bindings),
            'operation' => $this->parseQueryOperation($sql),
            'is_slow' => $timeMs > ($this->getSlowQueryThreshold() ?? 1000),
        ];
    }

    /**
     * Parse query operation type.
     */
    protected function parseQueryOperation(string $sql): string
    {
        if (preg_match('/^\s*SELECT/i', $sql)) return 'SELECT';
        if (preg_match('/^\s*INSERT/i', $sql)) return 'INSERT';
        if (preg_match('/^\s*UPDATE/i', $sql)) return 'UPDATE';
        if (preg_match('/^\s*DELETE/i', $sql)) return 'DELETE';
        if (preg_match('/^\s*ALTER/i', $sql)) return 'ALTER';
        if (preg_match('/^\s*CREATE/i', $sql)) return 'CREATE';
        if (preg_match('/^\s*DROP/i', $sql)) return 'DROP';
        if (preg_match('/^\s*TRUNCATE/i', $sql)) return 'TRUNCATE';
        return 'OTHER';
    }

    /**
     * Get slow query threshold (ms).
     * Override in service class to use config value.
     */
    protected function getSlowQueryThreshold(): ?float
    {
        return 1000;
    }

    /**
     * Extract table names from query.
     */
    protected function extractTableNames(string $sql): array
    {
        $tables = [];

        // Match FROM table_name
        if (preg_match_all('/FROM\s+`?(\w+)`?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Match JOIN table_name
        if (preg_match_all('/JOIN\s+`?(\w+)`?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Match INSERT INTO table_name
        if (preg_match_all('/INSERT\s+INTO\s+`?(\w+)`?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // Match UPDATE table_name
        if (preg_match_all('/UPDATE\s+`?(\w+)`?/i', $sql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        return array_unique($tables);
    }

    /**
     * Detect if query might cause N+1 problem.
     */
    protected function mightCauseNPlusOne(string $sql): bool
    {
        // Look for patterns that might indicate N+1
        return preg_match('/SELECT\s+.*\s+FROM/i', $sql) &&
               preg_match('/WHERE\s+.*=\s*\?/', $sql);
    }
}

