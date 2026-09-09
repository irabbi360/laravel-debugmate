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
    'debugmate_key' => env('DEBUGMATE_PROJECT_KEY', env('DEBUGMATE_KEY')),

    /**
     * What to track
     */
    'track_errors' => env('DEBUGMATE_TRACK_ERRORS', true),
    'track_logs' => env('DEBUGMATE_TRACK_LOGS', false),
    'track_performance' => env('DEBUGMATE_TRACK_PERFORMANCE', false),
    'track_queries' => env('DEBUGMATE_TRACK_QUERIES', true),
    'track_analytics' => env('DEBUGMATE_TRACK_ANALYTICS', false),
    'track_monitors' => env('DEBUGMATE_TRACK_MONITORS', false),

    /**
     * Uptime / SSL / domain monitors
     * DebugMate servers run the checks; this package only registers targets.
     */
    'monitors' => [
        'urls' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DEBUGMATE_MONITOR_URLS', ''))
        ))),
        'include_app_url' => env('DEBUGMATE_MONITOR_INCLUDE_APP_URL', true),
        'sync_on_boot' => env('DEBUGMATE_MONITOR_SYNC_ON_BOOT', false),
        'check_ssl' => env('DEBUGMATE_MONITOR_CHECK_SSL', true),
        'check_domain' => env('DEBUGMATE_MONITOR_CHECK_DOMAIN', true),
        'check_uptime' => env('DEBUGMATE_MONITOR_CHECK_UPTIME', true),
        'expected_status' => (int) env('DEBUGMATE_MONITOR_EXPECTED_STATUS', 200),
        'timeout_seconds' => (int) env('DEBUGMATE_MONITOR_TIMEOUT', 10),
        'replace' => env('DEBUGMATE_MONITOR_REPLACE', false),
    ],

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
     * Artisan commands to ignore.
     *
     * Long-running daemons MUST stay in this list. A daemon fires
     * CommandStarting but not CommandFinished for hours or days, so the trace
     * it opens is never flushed: spans and queries accumulate for the whole
     * process lifetime and the worker leaks memory until it is OOM-killed.
     *
     * Supports `*` wildcards (matched with Str::is).
     */
    'ignore_commands' => [
        // Long-running daemons - never let these open a trace
        'queue:work',
        'queue:listen',
        'horizon',
        'horizon:work',
        'horizon:supervisor',
        'schedule:work',
        'octane:start',
        'reverb:start',
        'websockets:serve',
        'pail',
        'serve',

        // Per-minute scheduler noise
        'schedule:run',
        'schedule:finish',

        // DebugMate's own commands - reporting on reporting
        'debugmate:*',

        // Trivial commands
        'list',
        'help',
        'clear-compiled',
    ],

    /**
     * Database tables to ignore in query tracking.
     *
     * Writing telemetry must not itself generate telemetry. Without this,
     * every INSERT into `jobs` or `performance_monitors` produces another
     * query span, which enlarges the next payload, which produces more
     * writes - an amplification loop that is unbounded when an app reports
     * to itself.
     *
     * Supports `*` wildcards (matched with Str::is).
     */
    'ignore_tables' => [
        // Queue / framework infrastructure
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'cache',
        'cache_locks',

        // DebugMate ingest tables (only present when self-hosting DebugMate)
        'debug_requests',
        'performance_monitors',
        'track_errors',
        'track_logs',
        'error_groups',
        'log_groups',
        'log_breadcrumbs',
        'uptime_checks',
        'debugmate_analytics_*',

        // Other profilers
        'telescope_*',
    ],

    /**
     * Hard in-memory caps per trace.
     *
     * These bound worst-case memory and payload size even if a trace is never
     * flushed. Overflow is dropped and counted, never buffered.
     */
    'limits' => [
        'max_spans' => (int) env('DEBUGMATE_MAX_SPANS', 500),
        'max_queries' => (int) env('DEBUGMATE_MAX_QUERIES', 200),
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
    'max_payload_size' => env('DEBUGMATE_MAX_PAYLOAD_SIZE', 262144), // 256KB

    /**
     * Queue job retry settings for async reporting
     */
    'job_retries' => env('DEBUGMATE_JOB_RETRIES', 3),
    'job_backoff_seconds' => [10, 30, 60],
    'job_timeout' => env('DEBUGMATE_JOB_TIMEOUT', 30),

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
