<?php

namespace Irabbi360\LaravelDebugMate\Tracing;

use Illuminate\Support\Str;

class Tracer
{
    /** OpenTelemetry Trace Flags */
    public const TRACE_FLAG_SAMPLED = 0x01;
    public const TRACE_FLAG_NOT_SAMPLED = 0x00;

    /** Fallback cap when config is unavailable (e.g. Tracer used outside Laravel). */
    public const DEFAULT_MAX_SPANS = 500;

    protected string $traceId;
    protected int $traceFlags = self::TRACE_FLAG_SAMPLED;
    protected array $spanStack = [];
    protected array $allSpans = [];
    protected int $maxSpans;
    protected int $droppedSpans = 0;

    public function __construct(?string $traceId = null, int $traceFlags = self::TRACE_FLAG_SAMPLED, ?int $maxSpans = null)
    {
        $this->traceId = $traceId ?? $this->generateTraceId();
        $this->traceFlags = $traceFlags;
        $this->maxSpans = $maxSpans ?? $this->resolveMaxSpans();
    }

    /**
     * Resolve the span cap from config, tolerating a bare (non-Laravel) context.
     */
    protected function resolveMaxSpans(): int
    {
        if (! function_exists('config')) {
            return self::DEFAULT_MAX_SPANS;
        }

        try {
            return (int) config('debugmate.limits.max_spans', self::DEFAULT_MAX_SPANS);
        } catch (\Throwable) {
            return self::DEFAULT_MAX_SPANS;
        }
    }

    /**
     * Start a new span (becomes current active span).
     */
    public function startSpan(string $name, array $attributes = []): Span
    {
        $parentSpanId = $this->getCurrentSpanId();
        $span = new Span($this->traceId, $name, $parentSpanId);

        if (!empty($attributes)) {
            $span->setAttributes($attributes);
        }

        $this->spanStack[] = $span;

        // Retain the span only while under the cap. Overflow is counted rather
        // than buffered, so an unflushed trace can never exhaust memory. The
        // span is still pushed onto the stack so parent/child nesting and
        // endSpan() stay balanced.
        if ($this->maxSpans <= 0 || count($this->allSpans) < $this->maxSpans) {
            $this->allSpans[] = $span;
        } else {
            $this->droppedSpans++;
        }

        return $span;
    }

    /**
     * Number of spans dropped because the cap was reached.
     */
    public function getDroppedSpanCount(): int
    {
        return $this->droppedSpans;
    }

    /**
     * The configured span cap for this trace.
     */
    public function getMaxSpans(): int
    {
        return $this->maxSpans;
    }

    /**
     * End the current active span and pop it off the stack.
     */
    public function endSpan(?Span $span = null): ?Span
    {
        if (empty($this->spanStack)) {
            return null;
        }

        $currentSpan = array_pop($this->spanStack);

        if ($span !== null && $span->getSpanId() !== $currentSpan->getSpanId()) {
            // Warn: ending wrong span
            $this->spanStack[] = $currentSpan;
            return null;
        }

        $currentSpan->end();
        return $currentSpan;
    }

    /**
     * Get the currently active span (top of stack).
     */
    public function getCurrentSpan(): ?Span
    {
        return end($this->spanStack) ?: null;
    }

    /**
     * Get the current span ID.
     */
    public function getCurrentSpanId(): ?string
    {
        $span = $this->getCurrentSpan();
        return $span?->getSpanId();
    }

    /**
     * Get all recorded spans.
     */
    public function getSpans(): array
    {
        return $this->allSpans;
    }

    /**
     * Get the trace ID.
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * Check if trace is sampled.
     */
    public function isSampled(): bool
    {
        return ($this->traceFlags & self::TRACE_FLAG_SAMPLED) !== 0;
    }

    /**
     * Get trace flags (for propagation).
     */
    public function getTraceFlags(): int
    {
        return $this->traceFlags;
    }

    /**
     * Get W3C Trace Context header value (for distributed tracing).
     * Format: traceparent: 00-<trace-id>-<span-id>-<trace-flags>
     */
    public function getTraceParent(): string
    {
        $spanId = $this->getCurrentSpanId() ?? '0000000000000000';
        $flags = str_pad(dechex($this->traceFlags), 2, '0', STR_PAD_LEFT);
        return "00-{$this->traceId}-{$spanId}-{$flags}";
    }

    /**
     * Create a tracer from W3C Trace Context header.
     */
    public static function fromTraceParent(string $traceParent): ?self
    {
        $parts = explode('-', $traceParent);
        if (count($parts) !== 4) {
            return null;
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        if ($version !== '00' || strlen($traceId) !== 32 || strlen($spanId) !== 16 || strlen($flags) !== 2) {
            return null;
        }

        return new self($traceId, (int)hexdec($flags));
    }

    /**
     * Serialize all spans to array.
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'trace_flags' => $this->traceFlags,
            'is_sampled' => $this->isSampled(),
            'spans' => array_map(fn($s) => $s->toArray(), $this->allSpans),
            'dropped_spans' => $this->droppedSpans,
        ];
    }

    /**
     * Generate a random trace ID (32 hex chars = 16 bytes).
     */
    protected function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }
}

