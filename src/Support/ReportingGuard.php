<?php

namespace Irabbi360\LaravelDebugMate\Support;

/**
 * Prevents DebugMate from reporting on itself.
 *
 * While a report is being built or sent, anything it triggers (a log line, a
 * nested exception, an HTTP client event) must not start another report -
 * otherwise a single failure can recurse until the stack or the queue blows up.
 */
class ReportingGuard
{
    protected static bool $active = false;

    public static function active(): bool
    {
        return static::$active;
    }

    /**
     * Run the callback unless a report is already in progress.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    public static function run(callable $callback): mixed
    {
        if (static::$active) {
            return null;
        }

        static::$active = true;

        try {
            return $callback();
        } finally {
            static::$active = false;
        }
    }
}
