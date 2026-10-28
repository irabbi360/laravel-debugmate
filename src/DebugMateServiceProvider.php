<?php

namespace Irabbi360\LaravelDebugMate;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Irabbi360\LaravelDebugMate\Collectors\CommandCollector;
use Irabbi360\LaravelDebugMate\Collectors\HttpClientCollector;
use Irabbi360\LaravelDebugMate\Collectors\JobCollector;
use Irabbi360\LaravelDebugMate\Collectors\LivewireCollector;
use Irabbi360\LaravelDebugMate\Collectors\QueryCollector;
use Irabbi360\LaravelDebugMate\Collectors\ViewCollector;
use Irabbi360\LaravelDebugMate\Console\RegisterMonitorsCommand;
use Irabbi360\LaravelDebugMate\Console\VerifyCommand;
use Irabbi360\LaravelDebugMate\Http\Middleware\TrackAnalytics;
use Irabbi360\LaravelDebugMate\Http\Middleware\TrackPerformance;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Support\ReportBuffer;
use Irabbi360\LaravelDebugMate\Services\BotDetector;
use Irabbi360\LaravelDebugMate\Services\ContextCollector;
use Irabbi360\LaravelDebugMate\Services\ErrorTracker;
use Irabbi360\LaravelDebugMate\Services\ExceptionHandler;
use Irabbi360\LaravelDebugMate\Services\GeoLocation;
use Irabbi360\LaravelDebugMate\Services\LogStreamer;
use Irabbi360\LaravelDebugMate\Services\MonitorRegistrar;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Services\RequestAnalytics;
use Irabbi360\LaravelDebugMate\Services\StackTraceParser;
use Irabbi360\LaravelDebugMate\Support\InternalLog;
use Irabbi360\LaravelDebugMate\Support\ReportingGuard;

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
                (string) config('debugmate.api_url'),
                (string) (config('debugmate.debugmate_key') ?? 'not-configured')
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

        // Scoped, not singleton: it holds the current visitor's session, which
        // must not leak into the next request under Octane or into queue jobs.
        $this->app->scoped(RequestAnalytics::class, function ($app) {
            return new RequestAnalytics(
                $app->make(ApiClient::class),
                config('debugmate')
            );
        });

        $this->app->singleton(GeoLocation::class, function ($app) {
            return new GeoLocation();
        });

        $this->app->singleton(MonitorRegistrar::class, function ($app) {
            return new MonitorRegistrar(
                $app->make(ApiClient::class),
                config('debugmate')
            );
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyCommand::class,
                RegisterMonitorsCommand::class,
            ]);
        }

        // Only load if enabled
        if (!config('debugmate.enabled')) {
            return;
        }

        // Register analytics middleware if enabled
        $this->registerAnalyticsMiddleware();

        // Register performance middleware if enabled
        $this->registerPerformanceMiddleware();

        // Sync-mode reports from a web request are batched and sent after the response
        $this->registerDeferredReporting();

        // Errors: register ExceptionHandler::handles() in bootstrap/app.php
        $this->registerLogListener();
        $this->registerPerformanceMonitoring();
        $this->registerMonitorSync();
    }

    /**
     * Optionally sync monitor targets on boot.
     */
    protected function registerMonitorSync(): void
    {
        if (! config('debugmate.track_monitors') || ! config('debugmate.monitors.sync_on_boot')) {
            return;
        }

        try {
            $this->app->make(MonitorRegistrar::class)->sync(
                async: (bool) config('debugmate.async_reporting', false)
            );
        } catch (\Throwable $e) {
            InternalLog::debug('DebugMate: Failed to sync monitors on boot: '.$e->getMessage());
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

        // Log::listen() receives Illuminate\Log\Events\MessageLogged, which
        // carries level / message / context but NOT the channel name.
        \Log::listen(function ($record) {
            try {
                // Prevent infinite recursion - don't process DebugMate's own logs,
                // nor anything logged while DebugMate is building/sending a report.
                if (isset($record->context[InternalLog::CONTEXT_KEY]) || ReportingGuard::active()) {
                    return;
                }

                $level = is_object($record->level) && method_exists($record->level, 'getName')
                    ? strtolower($record->level->getName())
                    : strtolower((string) ($record->level ?? 'info'));

                $message = is_scalar($record->message) || $record->message instanceof \Stringable
                    ? (string) $record->message
                    : json_encode($record->message);
                $context = is_array($record->context ?? null) ? $record->context : [];
                $channel = $record->channel ?? config('logging.default', 'stack');

                // Extract additional info if available
                $source = $this->detectSource();
                $traceId = $context['trace_id'] ?? null;

                // Stream the log
                $this->app->make(LogStreamer::class)->streamLog((string) $message, $level, array_merge($context, ['channel' => $channel]), $traceId, $source);
            } catch (\Throwable $e) {
                // Fail silently - don't break the app if logging fails
                error_log('DebugMate listener error: ' . $e->getMessage());
            }
        });
    }

    /**
     * Flush buffered reports once the response is out. The shutdown function is
     * a backstop for requests that end without terminating (fatal errors, exit).
     */
    protected function registerDeferredReporting(): void
    {
        ReportBuffer::reset();

        $flush = function () {
            if (ReportBuffer::count() === 0) {
                ReportBuffer::drain();

                return;
            }

            try {
                $this->app->make(ApiClient::class)->flushDeferred();
            } catch (\Throwable $e) {
                error_log('DebugMate flush error: '.$e->getMessage());
            }
        };

        $this->app->terminating($flush);
        register_shutdown_function($flush);

        $this->registerOctaneDeferredReporting($flush);
    }

    /**
     * Octane workers run in the console and never reach the shutdown function per
     * request, so buffer each request / task / tick on its own and flush it when
     * Octane reports it finished (after the response has been sent). Event names
     * are strings so laravel/octane stays an optional dependency.
     */
    protected function registerOctaneDeferredReporting(\Closure $flush): void
    {
        if (! $this->app->bound('octane')) {
            return;
        }

        $start = fn () => ReportBuffer::reset(forceDeferral: true);

        $finish = function () use ($flush) {
            $flush();
            ReportBuffer::forget();
        };

        $events = $this->app['events'];

        foreach (['RequestReceived', 'TaskReceived', 'TickReceived'] as $event) {
            $events->listen("Laravel\\Octane\\Events\\{$event}", $start);
        }

        foreach (['RequestTerminated', 'TaskTerminated', 'TickTerminated'] as $event) {
            $events->listen("Laravel\\Octane\\Events\\{$event}", $finish);
        }
    }

    /**
     * Detect source of log (http, queue, cli, test).
     */
    private function detectSource(): string
    {
        if (app()->runningUnitTests()) {
            return 'test';
        }

        if (app()->runningInConsole()) {
            return 'cli';
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
                    InternalLog::debug("DebugMate: Failed to register {$name} collector: " . $e->getMessage());
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
            InternalLog::debug('DebugMate: Failed to register analytics middleware: ' . $e->getMessage());
        }
    }

    /**
     * Register performance tracking middleware globally.
     */
    protected function registerPerformanceMiddleware(): void
    {
        if (! config('debugmate.track_performance')) {
            return;
        }

        try {
            $kernel = $this->app->make(Kernel::class);
            $kernel->pushMiddleware(TrackPerformance::class);
        } catch (\Throwable $e) {
            InternalLog::debug('DebugMate: Failed to register performance middleware: '.$e->getMessage());
        }
    }
}
