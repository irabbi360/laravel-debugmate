<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DebugMate SDK Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Enable or disable DebugMate SDK
     */
    'enabled' => env('DEBUGMATE_ENABLED', true),

    /**
     * DebugMate API URL
     */
    'api_url' => env('DEBUGMATE_API_URL', 'http://localhost:8000'),

    /**
     * Project Key (identifies your application)
     * This is the only required configuration
     */
    'debugmate_key' => env('DEBUGMATE_PROJECT_KEY'),

    /**
     * What to track
     */
    'track_errors' => env('DEBUGMATE_TRACK_ERRORS', true),
    'track_logs' => env('DEBUGMATE_TRACK_LOGS', false),
    'track_performance' => env('DEBUGMATE_TRACK_PERFORMANCE', false),
    'track_queries' => env('DEBUGMATE_TRACK_QUERIES', true),
    'track_analytics' => env('DEBUGMATE_TRACK_ANALYTICS', false),

    /**
     * Data collectors configuration
     * Each collector gathers spans for a specific feature
     */
    'collectors' => [
        'commands' => env('DEBUGMATE_COLLECT_COMMANDS', true),
        'jobs' => env('DEBUGMATE_COLLECT_JOBS', true),
        'views' => env('DEBUGMATE_COLLECT_VIEWS', true),
        'http_client' => env('DEBUGMATE_COLLECT_HTTP_CLIENT', true),
        'queries' => env('DEBUGMATE_COLLECT_QUERIES', true),
        'livewire' => env('DEBUGMATE_COLLECT_LIVEWIRE', true),
    ],

    /**
     * Queue configuration for async reporting
     */
    'queue' => env('QUEUE_CONNECTION', 'sync'),
    'async_reporting' => env('DEBUGMATE_ASYNC_REPORTING', true),

    /**
     * Paths to ignore from performance tracking
     * (prevents tracking of DebugMate API calls themselves)
     */
    'ignore_paths' => [
        'health',
        'ping',
        'api/debugmate/*',  // Ignore DebugMate API endpoints
        'debugmate',
        'debugbar',
    ],

    /**
     * Exception classes to ignore
     */
    'ignore_exceptions' => [
        // 'App\Exceptions\SomeException',
    ],

    /**
     * Sample rate for tracking (0.0 - 1.0)
     * 1.0 = track everything, 0.5 = track 50%, 0.1 = track 10%
     */
    'sample_rate' => env('DEBUGMATE_SAMPLE_RATE', 1.0),

    /**
     * Default context data added to all reports
     */
    'context' => [
        'environment' => env('APP_ENV', 'production'),
        'app_name' => env('APP_NAME', 'Laravel App'),
        'app_version' => env('APP_VERSION', '1.0.0'),
    ],

    /**
     * Performance thresholds for alerting
     */
    'thresholds' => [
        'query_time_ms' => 1000, // Alert if query takes > 1s
        'response_time_ms' => 5000, // Alert if response > 5s
    ],

    /**
     * Log channels to track
     */
    'log_channels' => [
        'stack',
        'single',
        'daily',
        // Add specific channels as needed
    ],

    /**
     * Maximum payload size (in bytes)
     */
    'max_payload_size' => 1048576, // 1MB

    /**
     * Request timeout (in seconds)
     */
    'request_timeout' => 10,

    /**
     * Enable detailed debugging
     */
    'debug' => env('DEBUGMATE_DEBUG', false),

    /**
     * Request Analytics Configuration
     * Tracks visitor behavior, page views, sessions, bounce rate, etc.
     */
    'analytics' => [
        'enabled' => env('DEBUGMATE_TRACK_ANALYTICS', false),
        'send_analytics_to_api' => env('DEBUGMATE_SEND_ANALYTICS_TO_API', true),
        'anonymize_ips' => env('DEBUGMATE_ANONYMIZE_IPS', true),
        'bot_filtering' => env('DEBUGMATE_BOT_FILTERING', true),
        'exclude_bots' => env('DEBUGMATE_EXCLUDE_BOTS', true),
        'retention_days' => env('DEBUGMATE_ANALYTICS_RETENTION_DAYS', 30),
        'sample_rate' => env('DEBUGMATE_ANALYTICS_SAMPLE_RATE', 1.0),
    ],

    /**
     * Geolocation Configuration
     * Enable IP geolocation lookups for visitor location tracking
     */
    'enable_geolocation' => env('DEBUGMATE_ENABLE_GEOLOCATION', false),
];
