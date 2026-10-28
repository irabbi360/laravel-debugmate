# DebugMate SDK Changelog

## [2.2.0] - Unreleased

### ⚠️ Changed

- **`async_reporting` now defaults to `false`.** Reports are sent directly to the API,
  so a fresh install works without a queue worker. Set `DEBUGMATE_ASYNC_REPORTING=true`
  (and run a worker) to report from queued jobs. Apps that published the config keep
  whatever value their published file has.

### ⚡ Performance

- **Web requests no longer wait on DebugMate.** Without async reporting, reports
  raised during a request are buffered and sent in one batch to the new
  `/api/debugmate/batch` endpoint after the response is sent (`app()->terminating()`,
  with a shutdown-function backstop). A request that logs 10 lines makes one API call,
  after the visitor already has the page. Older servers without the batch endpoint
  get the items one by one. New config: `defer_reporting`, `buffer_limit`,
  `max_batch_size`.
- **Laravel Octane support.** When Octane is installed, each request / task / tick
  is buffered separately (per coroutine under Swoole) and flushed on Octane's
  `RequestTerminated` / `TaskTerminated` / `TickTerminated`, after the response.
  `laravel/octane` remains optional.

### ✨ Added

- **`debugmate:verify` sends a test error** (`DebugMateTestException`) after the
  connection check and prints the link to the project's Errors page, so a new install
  can be confirmed in the dashboard. Skip it with `--skip-test-error`.

### 🐛 Fixes

- `debugmate:verify` never showed the project name (it read `name`; the API returns `title`).
- `RequestAnalytics` called a non-existent `InternalInternalLog` class when an
  analytics send failed.

### 🐛 Reliability — the SDK can no longer break the host app

- **Exception reporting never throws.** `ErrorTracker::reportError()` and the
  `ExceptionHandler::handles()` callback now catch every failure. Laravel does not
  guard report callbacks, so a reporting error used to replace the original
  exception and skip the app's own `laravel.log` entry.
- **UUID / ULID user keys** no longer cause a `TypeError` in the DTOs. Non-numeric
  ids are sent as `user_id: null` (the API validates it as an integer).
- **Connection failures are caught.** `ApiClient` caught only `RequestException`;
  Guzzle's `ConnectException` (DNS, refused, timeout) escaped and crashed the
  request when `async_reporting` was off.
- **Log tracking works.** `Log::listen()` receives `MessageLogged`, which has no
  `channel`, so every log was tagged `default` and dropped by `log_channels`. The
  app's default channel (`logging.default`) is now used.
- **Analytics:** a request without a `User-Agent` returned a 500. Fixed, and the
  whole analytics middleware is now best-effort.
- A re-entrancy guard (`ReportingGuard`) stops DebugMate from reporting on itself.
  SDK diagnostics go through `InternalLog`, which can no longer throw.
- `RequestAnalytics` is now `scoped` so visitor state cannot leak between
  requests under Octane. Tracers are reset per request/job/command.
- Performance payloads are sent in the middleware's `terminate()`, after the
  response, instead of delaying it.
- `LogStreamer::watch()` now sees file growth (`clearstatcache`) and handles rotation.
- Git info works with `packed-refs` and detached HEAD.

### 🔒 Security & privacy

- String query bindings are redacted (`redact_query_bindings`, on by default) in
  error context and performance payloads.
- Sensitive query parameters are masked in all reported URLs (request, referrer,
  page views, outgoing HTTP client calls).
- Stack-frame string arguments are no longer sent (`string(<len>)` instead).
- `QueryException` messages no longer include bound values.
- The live session id is no longer sent; cookie values are not sent.
- Log context now uses the full sanitizer (it used a weaker 5-key exact match).
- Larger built-in sensitive key list plus credential-shaped value detection; the
  `auth` key is matched exactly so `author_id` / `authenticated` are kept.
- Analytics settings `analytics.anonymize_ips` / `analytics.exclude_bots` /
  `analytics.send_analytics_to_api` were read from the wrong config path and had
  no effect. IPv6 is now anonymized, and the geolocation lookup receives the
  anonymized IP. The visitor fingerprint is an HMAC keyed with `APP_KEY`, so
  fingerprints change once after upgrading.
- `traceparent` is strictly validated (hex, non-zero) and the upstream parent span
  id is preserved.
- Livewire property values are no longer recorded (only their type).
- Requires `guzzlehttp/guzzle ^7.15.2` (CVE-2026-69246, CVE-2026-69245).
- `debugmate:verify` and the API client warn when the API URL is plain HTTP.

### 🔒 Security audit fixes

- **Secrets in URL paths are masked.** Route parameters with sensitive names are
  reported as placeholders (`/reset-password/{token}`, `/email/verify/42/{hash}`),
  and token-like path segments (64-char hex tokens, mixed-case random strings,
  Slack/Discord webhook secrets, Telegram bot tokens) become `***` in every
  reported URL: errors, logs, analytics page views, performance endpoints and
  outgoing HTTP spans. Slugs, numeric ids, UUIDs and ULIDs are kept.
- **No argument values in text stack traces.** `getTraceAsString()` embeds the
  first 15 characters of string arguments; log reports and span
  `exception.stacktrace` now use `SafeTrace`, which omits arguments.
- **The API client no longer follows redirects.** Guzzle strips only
  `Authorization`/`Cookie` on cross-origin redirects, so `X-DebugMate-Key`
  could be forwarded to the redirect target. A 3xx is now logged with a hint
  to fix `DEBUGMATE_API_URL`.
- **Messages are scrubbed.** Exception, log and span messages have embedded URLs
  sanitized and `secret=value` pairs, Bearer tokens and credential-shaped
  strings masked. Database driver messages have quoted values and PostgreSQL
  `Key (col)=(value)` details masked (MySQL/PostgreSQL unique-violation errors
  repeat the offending value).
- **The project key is no longer copied into analytics** sessions (cache) or
  payload bodies; it is sent only in the request headers. Sessions cached by
  older versions are scrubbed on load.

### 🔒 Security audit fixes (items 6–13)

- **Analytics abuse limits:** only GET HTML page loads are tracked (no JSON/XHR,
  no prefetch); `analytics.sample_rate` now applies to new sessions; new
  sessions are rate-limited per anonymized IP (`max_new_sessions_per_minute`,
  default 30); session cache TTL cut from 24 h to the cookie's 30 min; the
  geolocation lookup runs only for new sessions.
- **Privacy by default:** `send_user_pii` now defaults to **false**. New
  top-level `anonymize_ips` (default true) anonymizes client IPs in errors and
  logs and in proxy headers (`X-Forwarded-For`, `Forwarded`, `CF-Connecting-IP`, …).
- **Consent:** analytics can be gated by a cookie (`analytics.consent_cookie`) or
  `TrackAnalytics::resolveConsentUsing()`; without consent nothing is tracked
  and no `debugmate_sid` cookie is set.
- **Availability:** `connect_timeout` (2 s) and a lower `request_timeout` (5 s);
  a circuit breaker stops API calls for `circuit_breaker_seconds` after a
  connection failure, shared across workers through the cache. The client
  refuses to send when no API URL is configured.
- **Raw SQL:** single-quoted string literals are masked under
  `redact_query_bindings` (query spans, query log, `QueryException` SQL).
- **No report payloads in `failed_jobs`:** report jobs retry while attempts
  remain and then give up with a log line instead of failing.
- **`LogStreamer::streamFromFile()`** only accepts files inside `storage/logs`.
- **CI:** actions pinned to commit SHAs, `permissions: contents: read`,
  `persist-credentials: false`.
- **Octane / Swoole:** the re-entrancy guard is tracked per coroutine, so one
  in-flight report no longer drops other concurrent requests' reports.
- Removed the unused `analytics.retention_days` option (retention is a server concern).

### Changed

- **`user_id` is an optional, opaque identifier** for the user in the reporting
  app. Integer keys stay integers; UUID / ULID / string keys are sent as strings
  (max 255); guests send `null`. The DebugMate server must accept a nullable
  string for `user_id` (the analytics endpoint currently validates `integer`).
- **Code snippets** are attached to application frames only by default
  (`code_snippets`: `application` | `all` | `none`), and each source file is read
  once per report instead of once per frame.
- **Queue:** reports go to `queue_name` on `queue_connection` (new). The old
  `queue` key was never read and has been removed.
- **Supported:** Laravel 10, 11, 12 and 13 on PHP 8.2+ (Laravel 10 on PHP
  8.2–8.3). Laravel 10 apps register error reporting with
  `ExceptionHandler::registerOn($this)` in `app/Exceptions/Handler.php`, since
  `withExceptions()` only exists from Laravel 11.
- CI covers PHP 8.2–8.4 × Laravel 10–13 and audits runtime dependencies.

### ⚙️ New config

`redact_query_bindings`, `send_user_pii`, `sensitive_keys`,
`geolocation.endpoint`, `geolocation.timeout`, `code_snippets`,
`code_snippet_lines`, `queue_name`, `queue_connection`.

## [2.1.0] - September 8, 2026

### 🐛 Critical Fixes — worker memory leak & self-ingest amplification

Three defects combined to make a `queue:work` worker grow without bound and to
let a self-hosted DebugMate flood its own database.

#### 1. Daemon commands opened a trace that was never flushed

`CommandCollector` skipped only `list`, `help` and `clear-compiled`, so
`queue:work` — a daemon — fired `CommandStarting` and opened a root span that
stayed open until the process exited, potentially days later. Consequences:

- `Tracer::$allSpans` and `PerformanceMonitor::$queries` accumulated for the
  entire process lifetime.
- `hasActiveTrace()` stayed `true`, so `JobCollector` never flushed per job and
  folded every job into the single daemon trace instead.
- With the `database` queue driver the worker polls `jobs` every second, so it
  leaked roughly 86k spans/day even with zero traffic.
- The eventual flush emitted one enormous payload into `performance_monitors`.

**Fixed:** `shouldSkipCommand()` now matches the new `debugmate.ignore_commands`
config (wildcards supported). Known daemons — `queue:work`, `queue:listen`,
`horizon*`, `schedule:work`, `octane:start`, `reverb:start`, `websockets:serve`,
`pail`, `serve` — are additionally hardcoded in
`CommandCollector::ALWAYS_SKIPPED_COMMANDS` and cannot be re-enabled by config.
`schedule:run`, `schedule:finish` and `debugmate:*` are ignored by default too.

#### 2. `QueryCollector` recorded queries with no trace to flush them into

`recordQuery()` created a span for every query unconditionally, including
queries emitted while no request or job was active — those spans could never be
flushed, so they were pure leak. The collector also kept its **own**
`$queries` array of full SQL plus bindings that nothing ever reset.

**Fixed:** queries are ignored when `hasActiveTrace()` is `false`, and the
duplicate buffer is gone. Recorded queries now live solely on
`PerformanceMonitor`, which resets them on every trace start and flush.
`getQueries()`, `getSlowQueries()`, `getQueryCount()` and `getSlowQueryCount()`
are retained and delegate to the monitor.

#### 3. Writing telemetry generated more telemetry

When an app reports to a DebugMate instance backed by the same database, each
`INSERT` into `jobs` or `performance_monitors` was itself recorded as a query
span, enlarging the next payload, which caused more writes. `ignore_paths` did
not help: it only guards the inbound HTTP leg in `TrackPerformance`.

**Fixed:** new `debugmate.ignore_tables` config (wildcards supported) excludes
queue and framework infrastructure (`jobs`, `job_batches`, `failed_jobs`,
`sessions`, `cache`, `cache_locks`), DebugMate's own ingest tables and
`telescope_*`. Table names are parsed from the SQL and schema qualifiers are
stripped, so `` `mydb`.`jobs` `` matches while `jobs_archive` does not.

### 🛡️ Hardening

- **Hard caps per trace.** New `debugmate.limits.max_spans` (default 500) and
  `debugmate.limits.max_queries` (default 200). Overflow is counted, never
  buffered, so even an unflushed trace cannot exhaust memory. Set either to `0`
  to disable. Drop counts surface as `dropped_spans` / `dropped_queries` in the
  metric payload context and in `getSummary()`, so a truncated trace is visibly
  truncated instead of silently incomplete.
- **Payloads are trimmed before queueing.** `PayloadLimiter` previously ran only
  inside `ApiClient`, which is too late for a database queue — the untrimmed
  array was already serialized into `jobs.payload`. All dispatchers in
  `AsyncDispatch` now trim first.
- **`max_payload_size` default lowered from 1 MB to 256 KB.** Override with
  `DEBUGMATE_MAX_PAYLOAD_SIZE`.

### ⚠️ Upgrade notes

- Republish the config (`php artisan vendor:publish --tag=debugmate-config
  --force`) or merge the new `ignore_commands`, `ignore_tables` and `limits`
  keys into your existing `config/debugmate.php`.
- Performance metrics for `queue:work` and `schedule:run` are no longer
  reported. Per-job metrics still are, and are now correctly scoped to each job
  rather than merged into one daemon trace.
- Traces exceeding the new caps are truncated. Raise `DEBUGMATE_MAX_SPANS` /
  `DEBUGMATE_MAX_QUERIES` if you need deeper traces.

## [2.0.0] - May 3, 2026

### 🎉 Major Updates

#### Database Schema Alignment
The SDK has been updated to align with the new DebugMate database schema (v2.0):

**New Tables Supported:**
- `debug_requests` - Trace root linking (trace_id as UUID)
- `error_groups` - Error deduplication via fingerprinting
- `track_errors` - Enhanced error tracking with grouping
- `track_logs` - Log streaming with source tracking
- `performance_monitors` - Performance metrics with endpoint tracking

#### New Features

##### 1. **Distributed Tracing (trace_id)**
All SDK services now support unique trace ID generation and linking:

```php
// Automatic trace_id generation
DebugMate::reportError($exception);

// Or pass custom trace_id
DebugMate::reportError($exception, [], $traceId);
```

**Benefits:**
- Link errors, logs, and performance metrics across requests
- Full request lifecycle visibility
- Better debugging of distributed issues

##### 2. **Error Fingerprinting**
Errors are automatically grouped using smart fingerprinting:

```php
// Fingerprint generated from: error_type + file + line
// Same errors grouped together for better visibility
DebugMate::reportError($exception);
```

**Fingerprint Formula:**
```
md5(ExceptionClass . ':' . file . ':' . line)
```

##### 3. **Source Tracking for Logs**
Logs are automatically tagged with their source:

```php
// Automatically detected sources:
'http'   - Web requests
'cli'    - Console commands
'queue'  - Queue jobs
'test'   - Test suite
```

##### 4. **Enhanced Performance Monitoring**
Performance metrics now track more details:

```php
DebugMate::recordMetric('api_call', 150.5, [
    'endpoint' => '/api/users',
    'method' => 'GET',
    'status_code' => 200,
    'memory_usage' => 2097152,
]);
```

**New Fields:**
- `endpoint` - Request path/endpoint
- `method` - HTTP method
- `status_code` - Response status
- `memory_usage` - Peak memory usage

---

### 🔄 Breaking Changes

None - All changes are backward compatible! However, method signatures now support optional parameters.

**Updated Signatures:**

```php
// ErrorTracker
reportError(Throwable $exception, array $context = [], ?string $traceId = null)
reportCustomError(string $type, string $message, array $context = [], array $stackTrace = [], ?string $traceId = null)

// LogStreamer
streamLog(string $message, string $level, array $context = [], ?string $traceId = null, ?string $source = null)
log(string $channel, string $message, string $level = 'info', array $context = [], ?string $traceId = null)

// PerformanceMonitor
recordMetric(string $name, float $durationMs, array $context = [], ?string $traceId = null)
recordQuery(string $query, float $time, array $bindings = [], ?string $traceId = null)
```

---

### ✨ Improvements

#### ErrorTracker
- ✅ Added `generateTraceId()` - UUID generation
- ✅ Added `generateFingerprint()` - Smart error grouping
- ✅ Trace ID passed to API with error data
- ✅ Fingerprint used for error group matching

#### LogStreamer
- ✅ Added `detectSource()` - Automatic source detection
- ✅ Trace ID linking for request correlation
- ✅ Source field in log payload
- ✅ Better context handling for buffered logs

#### PerformanceMonitor
- ✅ Added `generateTraceId()` - UUID generation
- ✅ Added `detectEndpoint()` - Automatic endpoint extraction
- ✅ Enhanced metric data with endpoint/method
- ✅ Peak memory tracking
- ✅ Status code recording
- ✅ Query tracing with trace_id support

#### ApiClient
- ✅ Auto-adds `environment` field to all payloads
- ✅ Better error logging with endpoint info
- ✅ Improved timeout handling
- ✅ Consistent payload structure across all endpoints

---

### 📊 Usage Examples

#### Trace Linking
```php
// Capture trace_id early in request
$traceId = request()->header('X-Trace-ID') ?? Illuminate\Support\Str::uuid();

// Use same trace_id for all operations
try {
    // Some operation
} catch (Throwable $e) {
    DebugMate::reportError($e, [], $traceId);
}

DebugMate::log('app', 'Operation completed', 'info', [], $traceId);
DebugMate::recordMetric('operation_time', 123.45, [], $traceId);
```

#### Error Fingerprinting
```php
// Same errors automatically grouped
throw new ValidationException('Email invalid');
// Fingerprint: md5('ValidationException:/app/Services/UserService.php:42')

// Later...
throw new ValidationException('Email invalid'); // Different line, different fingerprint
// Fingerprint: md5('ValidationException:/app/Services/AuthService.php:105')
```

#### Source Tracking
```php
// In HTTP request
DebugMate::log('app', 'Request started', 'info');
// source = 'http'

// In console command
DebugMate::log('app', 'Task started', 'info');
// source = 'cli'

// In queue job
DebugMate::log('app', 'Job processing', 'info');
// source = 'queue'
```

#### Enhanced Metrics
```php
DebugMate::recordMetric('db_query', 250, [
    'endpoint' => '/api/users',
    'method' => 'POST',
    'status_code' => 201,
    'memory_usage' => memory_get_peak_usage(true),
]);
// Stored in performance_monitors with full context
```

---

### 🚀 Migration Guide

No migration needed! Update the SDK and it will work with the new schema automatically.

**Optional: Enable new features**

```php
// .env
DEBUGMATE_TRACK_PERFORMANCE=true
DEBUGMATE_TRACK_QUERIES=true
```

---

### 🔧 Configuration Updates

No config changes required. All new features use sensible defaults.

**Available Config:**
```php
config/debugmate.php

// Thresholds (for slow detection)
'thresholds' => [
    'query_time_ms' => 1000,      // Alert if query > 1s
    'response_time_ms' => 5000,   // Alert if response > 5s
]

// Tracking flags
'track_errors' => true,
'track_logs' => true,
'track_performance' => true,
'track_queries' => false,        // Disabled by default
```

---

### 🐛 Bug Fixes

- Fixed trace_id consistency across async jobs
- Improved fingerprint generation for better error grouping
- Enhanced context sanitization for sensitive data
- Better handling of requests outside HTTP context

---

### 📝 Developer Notes

#### Trace ID Generation
```php
// Uses Laravel's UUID helper
$traceId = \Illuminate\Support\Str::uuid()->toString();
// Format: "550e8400-e29b-41d4-a716-446655440000"
```

#### Fingerprint Generation
```php
// Algorithm: MD5(exception_class:file:line)
// Ensures same exceptions at same location are grouped
// Different line = different fingerprint (better grouping)
```

#### Source Detection
```php
// Auto-detects based on execution context
if (app()->runningInConsole()) return 'cli';
if (app()->runningUnitTests()) return 'test';
return 'http';
```

#### Endpoint Detection
```php
// Extracts path from request URL
parse_url(request()->url(), PHP_URL_PATH);
// "/api/users/123" from "https://example.com/api/users/123"
```

---

### 🎯 Next Steps

1. **Update to v2.0**: `composer update debugmate/sdk`
2. **Test trace linking**: Verify trace_id appears in your dashboard
3. **Monitor error groups**: See grouped errors on dashboard
4. **Enable query tracking**: Set `DEBUGMATE_TRACK_QUERIES=true` if needed
5. **Review new metrics**: Check performance_monitors table for enhanced data

---

### 📞 Support

For issues or questions about the new schema:
- Check INTEGRATION_GUIDE.md for examples
- Review main app models in app/Models/ directory
- See database migration for table structure

---

**Last Updated:** May 3, 2026  
**SDK Version:** 2.0.0  
**Schema Version:** 2.0  
**Compatibility:** Laravel 12+

