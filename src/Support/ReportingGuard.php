<?php

namespace Irabbi360\LaravelDebugMate\Support;

/**
 * Prevents DebugMate from reporting on itself.
 *
 * While a report is being built or sent, anything it triggers (a log line, a
 * nested exception, an HTTP client event) must not start another report -
 * otherwise a single failure can recurse until the stack or the queue blows up.
 *
 * The flag is tracked per execution context: under Swoole / OpenSwoole
 * (Octane) many requests run concurrently as coroutines in one process, and a
 * single process-wide flag would make one in-flight report silently drop the
 * reports of every other concurrent request.
 */
class ReportingGuard
{
    /** @var array<int|string, true> */
    protected static array $active = [];

    public static function active(): bool
    {
        return isset(static::$active[static::contextId()]);
    }

    /**
     * Run the callback unless a report is already in progress in this context.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    public static function run(callable $callback): mixed
    {
        $context = static::contextId();

        if (isset(static::$active[$context])) {
            return null;
        }

        static::$active[$context] = true;

        try {
            return $callback();
        } finally {
            unset(static::$active[$context]);
        }
    }

    /**
     * Current coroutine id under Swoole / OpenSwoole, otherwise the process.
     */
    public static function contextId(): int|string
    {
        foreach (['\Swoole\Coroutine', '\OpenSwoole\Coroutine'] as $coroutine) {
            if (class_exists($coroutine) && ($cid = $coroutine::getCid()) > 0) {
                return $cid;
            }
        }

        return 'main';
    }
}
