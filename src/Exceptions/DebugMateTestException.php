<?php

namespace Irabbi360\LaravelDebugMate\Exceptions;

use RuntimeException;

/**
 * Sent by `php artisan debugmate:verify` so the install can be confirmed in the DebugMate dashboard.
 */
class DebugMateTestException extends RuntimeException
{
    public static function make(): self
    {
        return new self('This is a test error from `php artisan debugmate:verify`. DebugMate is installed correctly.');
    }
}
