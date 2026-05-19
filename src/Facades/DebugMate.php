<?php

namespace Irabbi360\LaravelDebugMate\Facades;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Facade;
use Irabbi360\LaravelDebugMate\Services\ExceptionHandler;

class DebugMate extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'debugmate';
    }

    /**
     * Register DebugMate as the exception handler.
     *
     * Usage in bootstrap/app.php:
     * ->withExceptions(function (Exceptions $exceptions) {
     *     \Irabbi360\LaravelDebugMate\Facades\DebugMate::handles($exceptions);
     * })->create();
     */
    public static function handles(Exceptions $exceptions): void
    {
        ExceptionHandler::handles($exceptions);
    }
}

