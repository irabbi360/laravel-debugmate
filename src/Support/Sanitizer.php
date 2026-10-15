<?php

namespace Irabbi360\LaravelDebugMate\Support;

use Irabbi360\LaravelDebugMate\Concerns\CaptureSanitization;

/**
 * Standalone access to the SDK's sanitization rules for classes that do not
 * use the CaptureSanitization trait themselves (spans, middleware).
 */
class Sanitizer
{
    use CaptureSanitization;

    protected static ?self $instance = null;

    public static function instance(): self
    {
        return static::$instance ??= new self;
    }
}
