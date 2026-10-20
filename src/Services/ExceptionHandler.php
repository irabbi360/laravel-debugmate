<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Closure;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;

class ExceptionHandler
{
    protected ErrorTracker $errorTracker;

    public function __construct(ErrorTracker $errorTracker)
    {
        $this->errorTracker = $errorTracker;
    }

    /**
     * Register DebugMate as the exception reporter (Laravel 11+).
     *
     * Usage in bootstrap/app.php:
     * ->withExceptions(function (Exceptions $exceptions) {
     *     \Irabbi360\LaravelDebugMate\Services\ExceptionHandler::handles($exceptions);
     * })->create();
     */
    public static function handles(Exceptions $exceptions): void
    {
        if (static::shouldRegister()) {
            $exceptions->report(static::reporter());
        }
    }

    /**
     * Register DebugMate on an exception handler (Laravel 10, also works on 11+).
     *
     * Usage in app/Exceptions/Handler.php:
     * public function register(): void
     * {
     *     \Irabbi360\LaravelDebugMate\Services\ExceptionHandler::registerOn($this);
     * }
     */
    public static function registerOn(Handler $handler): void
    {
        if (static::shouldRegister()) {
            $handler->reportable(static::reporter());
        }
    }

    protected static function shouldRegister(): bool
    {
        return (bool) config('debugmate.enabled')
            && (bool) config('debugmate.track_errors', true)
            && (bool) config('debugmate.debugmate_key');
    }

    /**
     * The report callback. Laravel does not guard report callbacks; an
     * exception here would replace the original one and skip the app's own
     * logging, so it never throws.
     */
    protected static function reporter(): Closure
    {
        return function (\Throwable $e): void {
            try {
                app(ErrorTracker::class)->reportError($e);
            } catch (\Throwable $reportingFailure) {
                error_log('DebugMate: failed to report exception: '.$reportingFailure->getMessage());
            }
        };
    }
}
