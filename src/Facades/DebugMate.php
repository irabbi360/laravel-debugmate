<?php

namespace Irabbi360\LaravelDebugMate\Facades;

use Illuminate\Support\Facades\Facade;

class DebugMate extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'debugmate';
    }
}

