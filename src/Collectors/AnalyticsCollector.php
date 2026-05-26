<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Irabbi360\LaravelDebugMate\Services\RequestAnalytics;

/**
 * Analytics Collector - Collects visitor tracking and session data.
 *
 * This collector implements the CollectorInterface and automatically
 * registers analytics tracking without requiring manual configuration.
 */
class AnalyticsCollector implements CollectorInterface
{
    protected RequestAnalytics $analytics;

    public function __construct(RequestAnalytics $analytics)
    {
        $this->analytics = $analytics;
    }

    /**
     * Register the collector by hooking into request lifecycle.
     */
    public function register(): void
    {
        // Analytics is handled via middleware (TrackAnalytics)
        // This collector is here for consistency with other collectors
    }

    /**
     * Get collector name.
     */
    public function getName(): string
    {
        return 'analytics';
    }

    /**
     * Get current session ID.
     */
    public function getSessionId(): ?string
    {
        return $this->analytics->getSessionId();
    }

    /**
     * Get current user fingerprint.
     */
    public function getUserFingerprint(): ?string
    {
        return $this->analytics->getUserFingerprint();
    }
}

