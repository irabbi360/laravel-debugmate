<?php

namespace Irabbi360\LaravelDebugMate\Facades;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Facade;
use Irabbi360\LaravelDebugMate\Services\ExceptionHandler;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;

/**
 * @method static void reportError(\Throwable $exception, array $context = [])
 * @method static array getPerformanceSummary()
 * @method static void trackPageView(array $pageData)
 * @method static void trackEvent(string $eventName, array $eventData = [], string $category = null)
 * @method static string|null getSessionId()
 * @method static string|null getUserFingerprint()
 *
 * @see \Irabbi360\LaravelDebugMate\Services\ErrorTracker
 * @see \Irabbi360\LaravelDebugMate\Services\RequestAnalytics
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

    // ── Analytics Methods ──────────────────────────────────────────────────────

    /**
     * Track a page view.
     *
     * Usage:
     *   DebugMate::trackPageView([
     *       'url' => request()->url(),
     *       'title' => 'Page Title',
     *       'load_time_ms' => 1234,
     *       'status_code' => 200,
     *   ]);
     */
    public static function trackPageView(array $pageData): void
    {
        app(\Irabbi360\LaravelDebugMate\Services\RequestAnalytics::class)->trackPageView($pageData);
    }

    /**
     * Track a custom event.
     *
     * Usage:
     *   DebugMate::trackEvent('user_signup', [
     *       'plan' => 'premium',
     *       'currency' => 'USD',
     *   ], category: 'conversion');
     */
    public static function trackEvent(string $eventName, array $eventData = [], string $category = null): void
    {
        app(\Irabbi360\LaravelDebugMate\Services\RequestAnalytics::class)->trackEvent($eventName, $eventData, $category);
    }

    /**
     * Get current session ID.
     */
    public static function getSessionId(): ?string
    {
        return app(\Irabbi360\LaravelDebugMate\Services\RequestAnalytics::class)->getSessionId();
    }

    /**
     * Get current user fingerprint.
     */
    public static function getUserFingerprint(): ?string
    {
        return app(\Irabbi360\LaravelDebugMate\Services\RequestAnalytics::class)->getUserFingerprint();
    }
}
