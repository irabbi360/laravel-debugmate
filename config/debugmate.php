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
    'track_queries' => env('DEBUGMATE_TRACK_QUERIES', false),

    /**
     * Queue configuration for async reporting
     */
    'queue' => env('QUEUE_CONNECTION', 'sync'),
    'async_reporting' => env('DEBUGMATE_ASYNC_REPORTING', true),

    /**
     * Paths to ignore
     */
    'ignore_paths' => [
        'health',
        'ping',
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
];

