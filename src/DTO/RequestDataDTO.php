<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * RequestDataDTO - Data Transfer Object for Request Information
 *
 * Encapsulates all request-related data collected from an HTTP request.
 * Provides type safety and validation for request metrics.
 */
class RequestDataDTO implements Arrayable, JsonSerializable
{
    /**
     * Create a new RequestDataDTO instance.
     */
    public function __construct(
        public readonly string $ip_address,
        public readonly string $user_agent,
        public readonly string $method,
        public readonly string $path,
        public readonly int $status_code,
        public readonly float $response_time_ms,
        public readonly ?float $memory_usage = null,
        public readonly ?string $referrer = null,
        public readonly ?int $user_id = null,
        public readonly ?string $accept_language = null,
    ) {}

    /**
     * Create RequestDataDTO from request array.
     */
    public static function from(array $data): self
    {
        return new self(
            ip_address: $data['ip_address'] ?? '0.0.0.0',
            user_agent: $data['user_agent'] ?? '',
            method: $data['method'] ?? 'GET',
            path: $data['path'] ?? '/',
            status_code: $data['status_code'] ?? 200,
            response_time_ms: $data['response_time_ms'] ?? 0.0,
            memory_usage: $data['memory_usage'] ?? null,
            referrer: $data['referrer'] ?? null,
            user_id: $data['user_id'] ?? null,
            accept_language: $data['accept_language'] ?? null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->status_code,
            'response_time_ms' => $this->response_time_ms,
            'memory_usage' => $this->memory_usage,
            'referrer' => $this->referrer,
            'user_id' => $this->user_id,
            'accept_language' => $this->accept_language,
        ];
    }

    /**
     * Convert to JSON.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Check if response is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status_code >= 200 && $this->status_code < 300;
    }

    /**
     * Check if response is client error.
     */
    public function isClientError(): bool
    {
        return $this->status_code >= 400 && $this->status_code < 500;
    }

    /**
     * Check if response is server error.
     */
    public function isServerError(): bool
    {
        return $this->status_code >= 500;
    }
}

