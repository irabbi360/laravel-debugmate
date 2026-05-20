<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Irabbi360\LaravelDebugMate\Tracing\Span;

interface CollectorInterface
{
    /**
     * Get the name of this collector.
     */
    public function getName(): string;

    /**
     * Register the collector with the application.
     */
    public function register(): void;
}

