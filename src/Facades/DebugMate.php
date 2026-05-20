<?php

namespace Irabbi360\LaravelDebugMate\Facades;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Facade;
use Irabbi360\LaravelDebugMate\Services\ExceptionHandler;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;

/**
 * @method static void reportError(\Throwable $exception, array $context = [])
 * @method static array getPerformanceSummaryx()
 *
 * @see \Irabbi360\LaravelDebugMate\Services\ErrorTracker
 */
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

    /**
     * Start timing an operation.
     *
     * Usage:
     *   DebugMate::startMonitoring('payment-processing');
     *   // ... do work ...
     *   DebugMate::stopMonitoring('payment-processing');
     */
    public static function startMonitoring(string $name): void
    {
        app(PerformanceMonitor::class)->startMonitoring($name);
    }

    /**
     * Stop timing and report the metric.
     *
     * @return float Duration in milliseconds
     */
    public static function stopMonitoring(string $name, array $context = []): float
    {
        return app(PerformanceMonitor::class)->stopMonitoring($name, $context);
    }

    /**
     * Manually record a performance metric.
     */
    public static function recordMetric(string $name, float $durationMs, array $context = [], ?string $traceId = null): void
    {
        app(PerformanceMonitor::class)->recordMetric($name, $durationMs, $context, $traceId);
    }

    /**
     * Manually record a DB query metric.
     */
    public static function recordQuery(string $query, float $time, array $bindings = [], ?string $traceId = null): void
    {
        app(PerformanceMonitor::class)->recordQuery($query, $time, $bindings, $traceId);
    }

    /**
     * Get a summary of all recorded metrics.
     */
    public static function getPerformanceSummary(): array
    {
        return app(PerformanceMonitor::class)->getSummary();
    }
}
