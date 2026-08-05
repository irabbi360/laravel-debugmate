<?php

namespace Irabbi360\LaravelDebugMate\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LogLevel;

/**
 * SDK-internal logging that bypasses DebugMate log streaming.
 */
class InternalLog
{
    public const CONTEXT_KEY = '_debugmate_internal';

    public static function error(string $message, array $context = []): void
    {
        self::write(LogLevel::ERROR, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write(LogLevel::WARNING, $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write(LogLevel::DEBUG, $message, $context);
    }

    protected static function write(string $level, string $message, array $context): void
    {
        Log::log($level, $message, array_merge($context, [
            self::CONTEXT_KEY => true,
        ]));
    }
}
