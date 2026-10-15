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

    /** Span id of the upstream caller (from an incoming traceparent header). */
    protected ?string $remoteParentSpanId = null;

    public function __construct(?string $traceId = null, int $traceFlags = self::TRACE_FLAG_SAMPLED, ?int $maxSpans = null, ?string $remoteParentSpanId = null)
    {
        $this->traceId = $traceId ?? $this->generateTraceId();
        $this->traceFlags = $traceFlags;
        $this->maxSpans = $maxSpans ?? $this->resolveMaxSpans();
        $this->remoteParentSpanId = $remoteParentSpanId;
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
        // The first local span hangs off the upstream span, if there is one.
        $parentSpanId = $this->getCurrentSpanId() ?? (empty($this->allSpans) ? $this->remoteParentSpanId : null);
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
        // W3C Trace Context: lowercase hex, fixed lengths, all-zero ids invalid.
        // The header is client-controlled, so anything else is rejected.
        if (! preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', trim($traceParent), $m)) {
            return null;
        }

        [, $version, $traceId, $spanId, $flags] = $m;

        if ($version === 'ff'
            || $traceId === str_repeat('0', 32)
            || $spanId === str_repeat('0', 16)) {
            return null;
        }

        return new self($traceId, (int) hexdec($flags), null, $spanId);
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

