<?php

namespace Irabbi360\LaravelDebugMate;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Irabbi360\LaravelDebugMate\Collectors\AnalyticsCollector;
use Irabbi360\LaravelDebugMate\Collectors\CommandCollector;
use Irabbi360\LaravelDebugMate\Collectors\HttpClientCollector;
use Irabbi360\LaravelDebugMate\Collectors\JobCollector;
use Irabbi360\LaravelDebugMate\Collectors\LivewireCollector;
use Irabbi360\LaravelDebugMate\Collectors\QueryCollector;
use Irabbi360\LaravelDebugMate\Collectors\ViewCollector;
use Irabbi360\LaravelDebugMate\Http\Middleware\EnableQueryLogging;
use Irabbi360\LaravelDebugMate\Http\Middleware\TrackAnalytics;
use Irabbi360\LaravelDebugMate\Http\Middleware\TrackPerformance;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\BotDetector;
use Irabbi360\LaravelDebugMate\Services\ContextCollector;
use Irabbi360\LaravelDebugMate\Services\ErrorTracker;
use Irabbi360\LaravelDebugMate\Services\ExceptionHandler;
use Irabbi360\LaravelDebugMate\Services\GeoLocation;
use Irabbi360\LaravelDebugMate\Services\LogStreamer;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Services\RequestAnalytics;
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

        $this->app->singleton(BotDetector::class, function ($app) {
            return new BotDetector(config('debugmate.analytics', []));
        });

        $this->app->singleton(RequestAnalytics::class, function ($app) {
            return new RequestAnalytics(
                $app->make(ApiClient::class),
                config('debugmate')
            );
        });

        $this->app->singleton(GeoLocation::class, function ($app) {
            return new GeoLocation();
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

        // Only load if enabled
        if (!config('debugmate.enabled')) {
            return;
        }

        // Register analytics middleware if enabled
        $this->registerAnalyticsMiddleware();

        // Only register log listener - it handles both errors and logs
        // registerErrorHandler();  // DISABLED - errors handled in log listener
        $this->registerLogListener();
        $this->registerPerformanceMonitoring();
    }

    /**
     * Register error handler.
     */
    protected function registerErrorHandler(): void
    {
        if (!config('debugmate.track_errors')) {
            return;
        }

        $errorTracker = $this->app->make(ErrorTracker::class);

        // Use modern Log::listen() instead of deprecated illuminate.log event
        \Log::listen(function ($record) use ($errorTracker) {
            try {
                // Prevent infinite recursion - don't process DebugMate's own logs
                if (isset($record->context['_debugmate_internal'])) {
                    return;
                }

                // Extract from LogRecord
                // Handle both Monolog\LogRecord and older format
                $level = is_object($record->level) && method_exists($record->level, 'getName')
                    ? $record->level->getName()
                    : (string)($record->level ?? 'error');

                $context = $record->context ?? [];

                // Only track errors and above
                if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
                    // Check if this log contains an exception
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
            } catch (\Throwable $e) {
                // Fail silently - don't break the app
                // Mark as internal to prevent recursion
                error_log('DebugMate error handler failed: ' . $e->getMessage());
            }
        });
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

        // Laravel's Log::listen() passes a single LogRecord object
        \Log::listen(function ($record) use ($logStreamer) {
            try {
                // Prevent infinite recursion - don't process DebugMate's own logs
                if (isset($record->context['_debugmate_internal'])) {
                    return;
                }

                // Extract data from LogRecord
                // Handle both Monolog\LogRecord and older format
                $level = is_object($record->level) && method_exists($record->level, 'getName')
                    ? $record->level->getName()
                    : (string)($record->level ?? 'info');

                $message = $record->message ?? '';
                $context = $record->context ?? [];
                $channel = $record->channel ?? 'default';

                // Extract additional info if available
                $source = $this->detectSource();
                $traceId = $context['trace_id'] ?? null;

                // Stream the log
                $logStreamer->streamLog($message, $level, array_merge($context, ['channel' => $channel]), $traceId, $source);
            } catch (\Throwable $e) {
                // Fail silently - don't break the app if logging fails
                error_log('DebugMate listener error: ' . $e->getMessage());
            }
        });
    }

    /**
     * Detect source of log (http, queue, cli, test).
     */
    private function detectSource(): string
    {
        if (app()->runningInConsole()) {
            return 'cli';
        }

        if (app()->runningUnitTests()) {
            return 'test';
        }

        return 'http';
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

        // ── Auto-register collectors ───────────────────────────────────────
        // These collect spans for commands, jobs, views, queries, HTTP requests, and Livewire
        $this->registerCollectors($monitor);
    }

    /**
     * Register all data collectors.
     */
    protected function registerCollectors(PerformanceMonitor $monitor): void
    {
        $collectors = [
            'commands' => fn() => new CommandCollector($monitor),
            'jobs' => fn() => new JobCollector($monitor),
            'views' => fn() => new ViewCollector($monitor),
            'http_client' => fn() => new HttpClientCollector($monitor),
            'queries' => fn() => new QueryCollector($monitor),
            'livewire' => fn() => new LivewireCollector($monitor),
        ];

        foreach ($collectors as $name => $factory) {
            if (config("debugmate.collectors.{$name}", true)) {
                try {
                    $collector = $factory();
                    $collector->register();
                } catch (\Throwable $e) {
                    \Log::debug("DebugMate: Failed to register {$name} collector: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Register analytics middleware.
     */
    protected function registerAnalyticsMiddleware(): void
    {
        if (!config('debugmate.track_analytics')) {
            return;
        }

        try {
            $kernel = $this->app->make(Kernel::class);
            $kernel->pushMiddleware(TrackAnalytics::class);
        } catch (\Throwable $e) {
            \Log::debug('DebugMate: Failed to register analytics middleware: ' . $e->getMessage());
        }
    }
}
