<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Foundation\Configuration\Exceptions;

class ExceptionHandler
{
    protected ErrorTracker $errorTracker;

    public function __construct(ErrorTracker $errorTracker)
    {
        $this->errorTracker = $errorTracker;
    }

    /**
     * Register DebugMate as the exception handler.
     *
     * Usage in bootstrap/app.php:
     * ->withExceptions(function (Exceptions $exceptions) {
     *     \DebugMate\SDK\Services\ExceptionHandler::handles($exceptions);
     * })->create();
     */
    public static function handles(Exceptions $exceptions): void
    {
        // Only register if enabled and configured
        if (!config('debugmate.enabled') || !config('debugmate.debugmate_key')) {
            return;
        }

        $errorTracker = app(ErrorTracker::class);

        $exceptions->report(function (\Throwable $e) use ($errorTracker) {
            $errorTracker->reportError($e);
        });
    }
}

