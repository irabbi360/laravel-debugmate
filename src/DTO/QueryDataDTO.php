<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Irabbi360\LaravelDebugMate\Data\BaseDTO;

/**
 * QueryDataDTO - Data Transfer Object for Database Query Information
 */
class QueryDataDTO extends BaseDTO
{
    public function __construct(
        public readonly string $sql,
        public readonly float $time_ms,
        public readonly int $bindings_count = 0,
        public readonly ?string $operation = null,
        public readonly array $bindings = [],
        public readonly ?string $database = null,
    ) {}

    public static function from(array $data): static
    {
        $sql = $data['sql'] ?? '';
        return new static(
            sql: $sql,
            time_ms: $data['time_ms'] ?? 0.0,
            bindings_count: $data['bindings_count'] ?? ($data['bindings'] ? count($data['bindings']) : 0),
            operation: $data['operation'] ?? self::detectOperation($sql),
            bindings: $data['bindings'] ?? [],
            database: $data['database'] ?? 'default',
        );
    }

    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'time_ms' => $this->time_ms,
            'bindings_count' => $this->bindings_count,
            'operation' => $this->operation,
            'bindings' => $this->bindings,
            'database' => $this->database,
        ];
    }

    public static function detectOperation(string $sql): string
    {
        if (preg_match('/^\s*SELECT/i', $sql)) return 'SELECT';
        if (preg_match('/^\s*INSERT/i', $sql)) return 'INSERT';
        if (preg_match('/^\s*UPDATE/i', $sql)) return 'UPDATE';
        if (preg_match('/^\s*DELETE/i', $sql)) return 'DELETE';
        return 'QUERY';
    }

    public function isSlow(float $threshold = 1000): bool
    {
        return $this->time_ms >= $threshold;
    }

    public function isVeryFast(float $threshold = 1): bool
    {
        return $this->time_ms < $threshold;
    }

    public function getSummary(): string
    {
        return "{$this->operation}: {$this->time_ms}ms ({$this->bindings_count} bindings)";
    }
}


