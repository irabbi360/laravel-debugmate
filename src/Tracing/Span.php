<?php

namespace Irabbi360\LaravelDebugMate\Tracing;

use Illuminate\Support\Str;

class Span
{
    /** OpenTelemetry Span Status values */
    public const STATUS_UNSET = 'UNSET';
    public const STATUS_OK = 'OK';
    public const STATUS_ERROR = 'ERROR';

    protected string $spanId;
    protected string $traceId;
    protected ?string $parentSpanId = null;
    protected float $startTime;
    protected ?float $endTime = null;
    protected string $name;
    protected array $attributes = [];
    protected array $events = [];
    protected string $status = self::STATUS_UNSET;
    protected ?string $statusMessage = null;

    public function __construct(
        string $traceId,
        string $name,
        ?string $parentSpanId = null,
        ?float $startTime = null,
    ) {
        $this->traceId = $traceId;
        $this->spanId = $this->generateSpanId();
        $this->name = $name;
        $this->parentSpanId = $parentSpanId;
        $this->startTime = $startTime ?? microtime(true);
    }

    /**
     * End the span.
     */
    public function end(?float $endTime = null): void
    {
        $this->endTime = $endTime ?? microtime(true);
    }

    /**
     * Add an attribute to the span.
     */
    public function setAttribute(string $key, $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Add multiple attributes.
     */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }

    /**
     * Add an event to the span.
     */
    public function addEvent(string $name, array $attributes = [], ?float $timestamp = null): self
    {
        $this->events[] = new SpanEvent($name, $attributes, $timestamp ?? microtime(true));
        return $this;
    }

    /**
     * Set span status.
     */
    public function setStatus(string $status, ?string $message = null): self
    {
        $this->status = $status;
        $this->statusMessage = $message;
        return $this;
    }

    /**
     * Mark span as errored.
     */
    public function recordException(\Throwable $exception): self
    {
        $this->setStatus(self::STATUS_ERROR, $exception->getMessage());
        $this->setAttribute('exception.type', get_class($exception));
        $this->setAttribute('exception.message', $exception->getMessage());
        $this->setAttribute('exception.stacktrace', $exception->getTraceAsString());
        return $this;
    }

    /**
     * Get duration in milliseconds.
     */
    public function getDurationMs(): float
    {
        if ($this->endTime === null) {
            return 0.0;
        }
        return ($this->endTime - $this->startTime) * 1000;
    }

    /**
     * Check if span is ended.
     */
    public function isEnded(): bool
    {
        return $this->endTime !== null;
    }

    // ── Getters ──────────────────────────────────────────────────────────

    public function getSpanId(): string
    {
        return $this->spanId;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getParentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusMessage(): ?string
    {
        return $this->statusMessage;
    }

    /**
     * Serialize span to array (for API payload).
     */
    public function toArray(): array
    {
        return [
            'span_id' => $this->spanId,
            'trace_id' => $this->traceId,
            'parent_span_id' => $this->parentSpanId,
            'name' => $this->name,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'duration_ms' => $this->getDurationMs(),
            'attributes' => $this->attributes,
            'events' => array_map(fn($e) => $e->toArray(), $this->events),
            'status' => $this->status,
            'status_message' => $this->statusMessage,
        ];
    }

    /**
     * Generate a random span ID (16 hex chars = 8 bytes).
     */
    protected function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}

