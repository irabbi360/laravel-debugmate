<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Irabbi360\LaravelDebugMate\Data\BaseDTO;

/**
 * LogDataDTO - Data Transfer Object for Log Information
 *
 * Encapsulates log data collected from application logging.
 */
class LogDataDTO extends BaseDTO
{
    public function __construct(
        public readonly string $message,
        public readonly string $level,
        public readonly ?string $trace_id = null,
        public readonly ?string $channel = null,
        public readonly array $context = [],
        public readonly ?string $source = null,
        public readonly ?string $stack_trace = null,
        public readonly ?string $exception_class = null,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?int $user_id = null,
        public readonly ?string $url = null,
        public readonly ?string $timestamp = null,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            message: $data['message'] ?? 'Log message',
            level: $data['level'] ?? 'info',
            trace_id: $data['trace_id'] ?? null,
            channel: $data['channel'] ?? 'default',
            context: $data['context'] ?? [],
            source: $data['source'] ?? 'http',
            stack_trace: $data['stack_trace'] ?? null,
            exception_class: $data['exception_class'] ?? null,
            file: $data['file'] ?? null,
            line: $data['line'] ?? null,
            user_id: $data['user_id'] ?? null,
            url: $data['url'] ?? null,
            timestamp: $data['timestamp'] ?? now()->toDateTimeString(),
        );
    }

    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'level' => $this->level,
            'trace_id' => $this->trace_id,
            'channel' => $this->channel,
            'context' => $this->context,
            'source' => $this->source,
            'stack_trace' => $this->stack_trace,
            'exception_class' => $this->exception_class,
            'file' => $this->file,
            'line' => $this->line,
            'user_id' => $this->user_id,
            'url' => $this->url,
            'timestamp' => $this->timestamp,
        ];
    }

    public function isError(): bool
    {
        return in_array($this->level, ['error', 'critical', 'emergency', 'alert']);
    }

    public function isWarning(): bool
    {
        return in_array($this->level, ['warning']);
    }

    public function isDebug(): bool
    {
        return in_array($this->level, ['debug', 'info']);
    }

    public function hasException(): bool
    {
        return !is_null($this->exception_class) && !is_null($this->stack_trace);
    }

    public function getSummary(): string
    {
        return "[{$this->channel}] {$this->level}: {$this->message}";
    }
}


