<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Irabbi360\LaravelDebugMate\Data\BaseDTO;

/**
 * ErrorDataDTO - Data Transfer Object for Error/Exception Information
 *
 * Encapsulates all error-related data collected from exceptions.
 * Provides type safety and validation for error tracking.
 */
class ErrorDataDTO extends BaseDTO
{
    public function __construct(
        public readonly string $trace_id,
        public readonly string $error_type,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly int $code,
        public readonly ?string $stack_trace = null,
        public readonly array $frames = [],
        public readonly ?string $fingerprint = null,
        public readonly array $context = [],
        public readonly ?string $source = null,
        public readonly ?int $user_id = null,
        public readonly ?string $url = null,
        public readonly ?string $user_agent = null,
        public readonly ?string $ip_address = null,
        public readonly ?string $method = null,
        public readonly ?int $status_code = null,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            trace_id: $data['trace_id'] ?? \Illuminate\Support\Str::uuid()->toString(),
            error_type: $data['error_type'] ?? 'Exception',
            message: $data['message'] ?? 'Unknown error',
            file: $data['file'] ?? 'unknown',
            line: (int) ($data['line'] ?? 0),
            code: (int) ($data['code'] ?? 0),
            stack_trace: $data['stack_trace'] ?? null,
            frames: $data['frames'] ?? [],
            fingerprint: $data['fingerprint'] ?? null,
            context: $data['context'] ?? [],
            source: $data['source'] ?? null,
            user_id: $data['user_id'] ?? null,
            url: $data['url'] ?? null,
            user_agent: $data['user_agent'] ?? null,
            ip_address: $data['ip_address'] ?? null,
            method: $data['method'] ?? null,
            status_code: $data['status_code'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'trace_id' => $this->trace_id,
            'error_type' => $this->error_type,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'code' => $this->code,
            'stack_trace' => $this->stack_trace,
            'frames' => $this->frames,
            'fingerprint' => $this->fingerprint,
            'context' => $this->context,
            'source' => $this->source,
            'user_id' => $this->user_id,
            'url' => $this->url,
            'user_agent' => $this->user_agent,
            'ip_address' => $this->ip_address,
            'method' => $this->method,
            'status_code' => $this->status_code,
        ];
    }

    public function isServerError(): bool
    {
        return $this->status_code >= 500 || empty($this->status_code);
    }

    public function isClientError(): bool
    {
        return $this->status_code >= 400 && $this->status_code < 500;
    }

    public function getShortPath(): string
    {
        $parts = explode('/', $this->file);
        return end($parts);
    }

    public function getSignature(): string
    {
        return "{$this->error_type}:{$this->getShortPath()}:{$this->line}";
    }
}


