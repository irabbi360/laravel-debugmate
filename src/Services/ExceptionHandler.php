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
     *     \Irabbi360\LaravelDebugMate\Services\ExceptionHandler::handles($exceptions);
     * })->create();
     */
    public static function handles(Exceptions $exceptions): void
    {
        if (! config('debugmate.enabled')
            || ! config('debugmate.track_errors', true)
            || ! config('debugmate.debugmate_key')) {
            return;
        }

        $exceptions->report(function (\Throwable $e) {
            // Laravel does not guard report callbacks; an exception here would
            // replace the original one and skip the app's own logging.
            try {
                app(ErrorTracker::class)->reportError($e);
            } catch (\Throwable $reportingFailure) {
                error_log('DebugMate: failed to report exception: '.$reportingFailure->getMessage());
            }
        });
    }
}

