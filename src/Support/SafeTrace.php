<?php

namespace Irabbi360\LaravelDebugMate\Support;

use Throwable;

/**
 * Stack traces as text, without arguments.
 *
 * Throwable::getTraceAsString() embeds the first 15 characters of every string
 * argument - e.g. "#0 Hasher->check('hunter2-secret-...')" - which leaks
 * passwords and tokens. This renders the same layout with "()" instead.
 */
class SafeTrace
{
    public static function asString(Throwable $exception, int $maxFrames = 100): string
    {
        $lines = [];

        foreach (array_slice($exception->getTrace(), 0, $maxFrames) as $i => $frame) {
            $location = isset($frame['file'])
                ? $frame['file'].'('.($frame['line'] ?? '?').')'
                : '[internal function]';

            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');

            $lines[] = "#{$i} {$location}: {$call}()";
        }

        $lines[] = '#'.count($lines).' {main}';

        return implode("\n", $lines);
    }
}
