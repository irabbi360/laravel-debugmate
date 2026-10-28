<?php

namespace Irabbi360\LaravelDebugMate\Support;

/**
 * Holds sync-mode reports raised during a web request so they can be sent in
 * one batch after the response has gone to the browser (app terminating, or
 * Octane's RequestTerminated), instead of one blocking API call per error /
 * log line / page view.
 *
 * Console commands and queue workers have no response to protect and no
 * per-job terminate hook, so they keep sending immediately.
 *
 * State is kept per execution context (see ReportingGuard): under Swoole /
 * OpenSwoole (Octane) concurrent requests run as coroutines in one process and
 * must not share - or flush - each other's reports.
 */
class ReportBuffer
{
    /** @var array<int|string, list<array{type: string, payload: array}>> */
    protected static array $items = [];

    /** @var array<int|string, int> */
    protected static array $dropped = [];

    /**
     * Once flushed, anything reported later in the request is sent directly.
     *
     * @var array<int|string, true>
     */
    protected static array $flushed = [];

    /**
     * Contexts that buffer even though PHP runs in the console (Octane requests, tests).
     *
     * @var array<int|string, bool>
     */
    protected static array $forceDeferral = [];

    public static function shouldDefer(): bool
    {
        $context = ReportingGuard::contextId();

        if (isset(static::$flushed[$context]) || ! config('debugmate.defer_reporting', true)) {
            return false;
        }

        return static::$forceDeferral[$context] ?? ! app()->runningInConsole();
    }

    public static function push(string $type, array $payload): void
    {
        $context = ReportingGuard::contextId();

        if (count(static::$items[$context] ?? []) >= max(1, (int) config('debugmate.buffer_limit', 200))) {
            static::$dropped[$context] = (static::$dropped[$context] ?? 0) + 1;

            return;
        }

        static::$items[$context][] = ['type' => $type, 'payload' => $payload];
    }

    /**
     * Take everything buffered so far and stop buffering for the rest of the request.
     *
     * @return array{items: list<array{type: string, payload: array}>, dropped: int}
     */
    public static function drain(): array
    {
        $context = ReportingGuard::contextId();
        $drained = ['items' => static::$items[$context] ?? [], 'dropped' => static::$dropped[$context] ?? 0];

        unset(static::$items[$context], static::$dropped[$context], static::$forceDeferral[$context]);
        static::$flushed[$context] = true;

        return $drained;
    }

    /**
     * Start buffering for a new unit of work in this context (Octane request / task, tests).
     */
    public static function reset(?bool $forceDeferral = null): void
    {
        $context = ReportingGuard::contextId();

        static::forget();

        if ($forceDeferral !== null) {
            static::$forceDeferral[$context] = $forceDeferral;
        }
    }

    /**
     * Drop all state for this context; long-running workers call it once a request is done
     * so finished coroutines don't accumulate entries.
     */
    public static function forget(): void
    {
        $context = ReportingGuard::contextId();

        unset(
            static::$items[$context],
            static::$dropped[$context],
            static::$flushed[$context],
            static::$forceDeferral[$context],
        );
    }

    public static function count(): int
    {
        return count(static::$items[ReportingGuard::contextId()] ?? []);
    }
}
