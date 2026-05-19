<?php

namespace Irabbi360\LaravelDebugMate\Providers;

use Illuminate\Support\ServiceProvider;
use Irabbi360\LaravelDebugMate\Http\Middleware\EnableQueryLogging;

class DebugMateServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Register middleware to enable query logging
        $this->app['router']->pushMiddlewareToGroup('web', EnableQueryLogging::class);
    }
}

