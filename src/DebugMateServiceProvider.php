<?php

namespace Irabbi360\LaravelDebugMate;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Irabbi360\LaravelDebugMate\Http\Middleware\EnableQueryLogging;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\ContextCollector;
use Irabbi360\LaravelDebugMate\Services\ErrorTracker;
use Irabbi360\LaravelDebugMate\Services\LogStreamer;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Services\ExceptionHandler;
use Irabbi360\LaravelDebugMate\Services\StackTraceParser;

class DebugMateServiceProvider extends ServiceProvider
{
    /**
     * Register services into the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/debugmate.php',
            'debugmate'
        );

        // Register singletons
        $this->app->singleton(ApiClient::class, function ($app) {
            return new ApiClient(
                config('debugmate.api_url'),
                config('debugmate.debugmate_key') ?? 'not-configured'
            );
        });

        $this->app->singleton(StackTraceParser::class, function ($app) {
            return new StackTraceParser();
        });

        $this->app->singleton(ContextCollector::class, function ($app) {
            return new ContextCollector();
        });

        $this->app->singleton(ErrorTracker::class, function ($app) {
            return new ErrorTracker(
                $app->make(ApiClient::class),
                $app->make(StackTraceParser::class),
                $app->make(ContextCollector::class),
                config('debugmate')
            );
        });

        $this->app->singleton(PerformanceMonitor::class, function ($app) {
            return new PerformanceMonitor(
                $app->make(ApiClient::class),
                config('debugmate')
            );
        });

        $this->app->singleton(LogStreamer::class, function ($app) {
            return new LogStreamer(
                $app->make(ApiClient::class),
                config('debugmate')
            );
        });

        $this->app->singleton(ExceptionHandler::class, function ($app) {
            return new ExceptionHandler($app->make(ErrorTracker::class));
        });

        // Bind the 'debugmate' facade accessor to ErrorTracker
        $this->app->bind('debugmate', function ($app) {
            return $app->make(ErrorTracker::class);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/debugmate.php' => config_path('debugmate.php'),
        ], 'config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'migrations');

        // Only load if enabled
        if (!config('debugmate.enabled')) {
            return;
        }

        // Enable query logging immediately for all requests
        DB::enableQueryLog();

        // Register error handler
        $this->registerErrorHandler();

        // Register log listener
        $this->registerLogListener();

        // Register performance monitoring
        $this->registerPerformanceMonitoring();
    }

    /**
     * Register error handler.
     */
    protected function registerErrorHandler(): void
    {
        $errorTracker = $this->app->make(ErrorTracker::class);

        // Listen to application exceptions using event
        if (config('debugmate.track_errors')) {
            \Event::listen('illuminate.log', function ($level, $message, $context) use ($errorTracker) {
                // Only track errors and above
                if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
                    if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                        $exception = $context['exception'];
                        if (!in_array(get_class($exception), config('debugmate.ignore_exceptions', []))) {
                            $errorTracker->reportError($exception, [
                                'user_id' => auth()->id() ?? null,
                                'route' => request()?->path() ?? null,
                                'method' => request()?->method() ?? null,
                                'ip' => request()?->ip() ?? null,
                            ]);
                        }
                    }
                }
            });
        }
    }

    /**
     * Register log listener.
     */
    protected function registerLogListener(): void
    {
        if (!config('debugmate.track_logs')) {
            return;
        }

        $logStreamer = $this->app->make(LogStreamer::class);

        \Log::listen(function ($level, $message, $context) use ($logStreamer) {
            $logStreamer->streamLog($message, $level, $context);
        });
    }

    /**
     * Register performance monitoring.
     */
    protected function registerPerformanceMonitoring(): void
    {
        if (!config('debugmate.track_performance')) {
            return;
        }

        $monitor = $this->app->make(PerformanceMonitor::class);

        // Track middleware timing
        \Event::listen('illuminate.query', function ($query, $bindings, $time) use ($monitor) {
            if (config('debugmate.track_queries')) {
                $monitor->recordQuery($query, $time, $bindings);
            }
        });
    }
}

