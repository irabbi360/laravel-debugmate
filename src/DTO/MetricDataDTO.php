<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Irabbi360\LaravelDebugMate\Data\BaseDTO;

/**
 * MetricDataDTO - Data Transfer Object for Performance Metrics
 */
class MetricDataDTO extends BaseDTO
{
    public function __construct(
        public readonly string $trace_id,
        public readonly string $endpoint,
        public readonly string $method,
        public readonly float $duration_ms,
        public readonly int $status_code,
        public readonly ?int $memory_usage = null,
        public readonly bool $threshold_exceeded = false,
        public readonly ?string $environment = null,
        public readonly int $query_count = 0,
        public readonly int $slow_query_count = 0,
        public readonly array $slow_queries = [],
        public readonly array $all_queries = [],
        public readonly array $spans = [],
        public readonly array $context = [],
        public readonly string $timestamp = '',
        // Span type data
        public readonly array $job_spans = [],
        public readonly array $command_spans = [],
        public readonly array $http_client_spans = [],
        public readonly array $view_spans = [],
        public readonly array $livewire_spans = [],
        public readonly int $job_count = 0,
        public readonly int $command_count = 0,
        public readonly int $http_client_count = 0,
        public readonly int $view_count = 0,
        public readonly int $livewire_count = 0,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            trace_id: $data['trace_id'] ?? \Illuminate\Support\Str::uuid()->toString(),
            endpoint: $data['endpoint'] ?? '/',
            method: $data['method'] ?? 'GET',
            duration_ms: $data['duration_ms'] ?? 0.0,
            status_code: $data['status_code'] ?? 200,
            memory_usage: $data['memory_usage'] ?? null,
            threshold_exceeded: $data['threshold_exceeded'] ?? false,
            environment: $data['environment'] ?? null,
            query_count: $data['query_count'] ?? 0,
            slow_query_count: $data['slow_query_count'] ?? 0,
            slow_queries: $data['slow_queries'] ?? [],
            all_queries: $data['all_queries'] ?? [],
            spans: $data['spans'] ?? [],
            context: $data['context'] ?? [],
            timestamp: $data['timestamp'] ?? now()->toIso8601String(),
            job_spans: $data['job_spans'] ?? [],
            command_spans: $data['command_spans'] ?? [],
            http_client_spans: $data['http_client_spans'] ?? [],
            view_spans: $data['view_spans'] ?? [],
            livewire_spans: $data['livewire_spans'] ?? [],
            job_count: $data['job_count'] ?? 0,
            command_count: $data['command_count'] ?? 0,
            http_client_count: $data['http_client_count'] ?? 0,
            view_count: $data['view_count'] ?? 0,
            livewire_count: $data['livewire_count'] ?? 0,
        );
    }

    public function toArray(): array
    {
        return [
            'trace_id' => $this->trace_id,
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'duration_ms' => $this->duration_ms,
            'status_code' => $this->status_code,
            'memory_usage' => $this->memory_usage,
            'threshold_exceeded' => $this->threshold_exceeded,
            'environment' => $this->environment,
            'query_count' => $this->query_count,
            'slow_query_count' => $this->slow_query_count,
            'slow_queries' => $this->slow_queries,
            'all_queries' => $this->all_queries,
            'spans' => $this->spans,
            'context' => $this->context,
            'timestamp' => $this->timestamp,
            'job_spans' => $this->job_spans,
            'command_spans' => $this->command_spans,
            'http_client_spans' => $this->http_client_spans,
            'view_spans' => $this->view_spans,
            'livewire_spans' => $this->livewire_spans,
            'job_count' => $this->job_count,
            'command_count' => $this->command_count,
            'http_client_count' => $this->http_client_count,
            'view_count' => $this->view_count,
            'livewire_count' => $this->livewire_count,
        ];
    }

    public function isServerError(): bool
    {
        return $this->status_code >= 500;
    }

    public function isClientError(): bool
    {
        return $this->status_code >= 400 && $this->status_code < 500;
    }

    public function isSuccessful(): bool
    {
        return $this->status_code >= 200 && $this->status_code < 300;
    }

    public function hasSlowQueries(): bool
    {
        return $this->slow_query_count > 0;
    }

    public function getMemoryUsageMb(): float
    {
        return round($this->memory_usage ? $this->memory_usage / 1024 / 1024 : 0, 2);
    }

    public function getSummary(): string
    {
        return "{$this->method} {$this->endpoint}: {$this->duration_ms}ms ({$this->status_code})";
    }
}



