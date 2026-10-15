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
    'api_url' => env('DEBUGMATE_API_URL', 'https://debugmate.devstarit.com'),

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
     *
     * - queue_connection: null uses the app's default queue connection.
     * - queue_name: put DebugMate jobs on their own queue so a burst of
     *   reports never delays your application's jobs.
     */
    'async_reporting' => env('DEBUGMATE_ASYNC_REPORTING', true),
    'queue_connection' => env('DEBUGMATE_QUEUE_CONNECTION'),
    'queue_name' => env('DEBUGMATE_QUEUE', 'default'),

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
     * Data privacy
     *
     * - redact_query_bindings: replace string query bindings with "***" before
     *   they leave the app (numbers / booleans / nulls are kept).
     * - send_user_pii: include the authenticated user's name and email
     *   (off by default - personal data under GDPR).
     * - anonymize_ips: truncate client IPs in errors, logs and analytics
     *   (IPv4 -> a.b.c.0, IPv6 -> /48), including X-Forwarded-For style headers.
     * - sensitive_keys: extra key fragments to mask (in addition to the
     *   built-in list of password / token / secret / cookie / ... keys).
     */
    'redact_query_bindings' => env('DEBUGMATE_REDACT_QUERY_BINDINGS', true),
    'send_user_pii' => env('DEBUGMATE_SEND_USER_PII', false),
    'anonymize_ips' => env('DEBUGMATE_ANONYMIZE_IPS', true),
    'sensitive_keys' => [
        // 'national_id',
    ],

    /**
     * Source code sent with stack frames
     *
     * - application: only for your app's frames (not vendor / node_modules)
     * - all: every frame (larger payloads; ships vendor source too)
     * - none: never send source code
     */
    'code_snippets' => env('DEBUGMATE_CODE_SNIPPETS', 'application'),
    'code_snippet_lines' => (int) env('DEBUGMATE_CODE_SNIPPET_LINES', 30),

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
    'request_timeout' => (float) env('DEBUGMATE_REQUEST_TIMEOUT', 5),
    'connect_timeout' => (float) env('DEBUGMATE_CONNECT_TIMEOUT', 2),

    /**
     * After a connection failure or timeout, stop calling the API for this
     * many seconds so a DebugMate outage never slows every request down.
     */
    'circuit_breaker_seconds' => (int) env('DEBUGMATE_CIRCUIT_BREAKER_SECONDS', 30),

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
        // Fraction of NEW visitor sessions to track (existing sessions continue).
        'sample_rate' => env('DEBUGMATE_ANALYTICS_SAMPLE_RATE', 1.0),
        // Cap on new sessions per client IP per minute; beyond it requests are
        // not tracked (stops cache / API flooding from cookieless clients).
        'max_new_sessions_per_minute' => (int) env('DEBUGMATE_ANALYTICS_MAX_NEW_SESSIONS', 30),
        // Consent: when set, visitors are only tracked (and the debugmate_sid
        // cookie is only set) if this cookie is present with a truthy value.
        // For custom logic use TrackAnalytics::resolveConsentUsing(fn ($request) => ...).
        'consent_cookie' => env('DEBUGMATE_ANALYTICS_CONSENT_COOKIE'),
    ],

    /**
     * Geolocation Configuration
     * Enable IP geolocation lookups for visitor location tracking
     */
    'enable_geolocation' => env('DEBUGMATE_ENABLE_GEOLOCATION', false),

    /**
     * Geolocation lookup endpoint. The (anonymized, when analytics.anonymize_ips
     * is on) visitor IP is appended to this URL. The default ip-api.com free
     * tier is HTTP-only and non-commercial - use an HTTPS provider in production.
     */
    'geolocation' => [
        'endpoint' => env('DEBUGMATE_GEOLOCATION_ENDPOINT', 'http://ip-api.com/json/'),
        'timeout' => (float) env('DEBUGMATE_GEOLOCATION_TIMEOUT', 1),
    ],
];
