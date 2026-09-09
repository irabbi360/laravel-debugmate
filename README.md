# DebugMate SDK

Error tracking, log streaming, and performance monitoring for Laravel apps.

## Features

- Error tracking with stack frames and fingerprinting
- Performance monitoring with OpenTelemetry-style spans
- Log streaming
- Request analytics
- Query / job / command / view / HTTP / Livewire collectors
- Uptime / SSL / domain monitor registration (checks run on DebugMate)
- Async reporting via queues

## Installation

```bash
composer require irabbi360/laravel-debugmate
```

## Quick Start

### 1. Environment

```env
DEBUGMATE_ENABLED=true
DEBUGMATE_API_URL=https://your-debugmate-host
DEBUGMATE_PROJECT_KEY=dm_your_project_key
DEBUGMATE_TRACK_ERRORS=true
DEBUGMATE_TRACK_PERFORMANCE=true
```

Package auto-registers `TrackPerformance` when `DEBUGMATE_TRACK_PERFORMANCE=true` (no `bootstrap/app.php` edit needed).

### 2. Register exception handler

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    \Irabbi360\LaravelDebugMate\Services\ExceptionHandler::handles($exceptions);
})->create();
```

### 3. Manual reporting

```php
use Irabbi360\LaravelDebugMate\Facades\DebugMate;

try {
    // Your code
} catch (Exception $e) {
    DebugMate::reportError($e, [
        'user_id' => auth()->id(),
        'route' => request()->path(),
    ]);
}
```

### Performance monitoring

```php
use Irabbi360\LaravelDebugMate\Facades\DebugMate;

DebugMate::startMonitoring('database_query');
// ... your code ...
DebugMate::stopMonitoring('database_query', ['query' => 'SELECT...']);
```

### Logs

```php
use Irabbi360\LaravelDebugMate\Facades\DebugMate;

DebugMate::log('stack', 'Log message', 'info', ['context_data']);
```

Only channels listed in `config/debugmate.php` `log_channels` are streamed when `DEBUGMATE_TRACK_LOGS=true`.

### Uptime / SSL / domain monitors

DebugMate servers run the checks. This package only registers target URLs.

```env
DEBUGMATE_TRACK_MONITORS=true
DEBUGMATE_MONITOR_URLS=https://example.com,https://api.example.com
DEBUGMATE_MONITOR_INCLUDE_APP_URL=true
```

Register (add to client scheduler daily):

```bash
php artisan debugmate:register-monitors --sync
```

### Verify setup

```bash
php artisan debugmate:verify
```

## Auth

SDK sends `X-DebugMate-Key` and `Authorization: Bearer {DEBUGMATE_PROJECT_KEY}` on every request.

`debugmate:verify` POSTs `/api/debugmate/verify-token` so it tests the same auth path as error ingest.

| Endpoint | Method |
|----------|--------|
| `/api/debugmate/verify-token` | GET, POST |
| `/api/debugmate/project` | GET |
| `/api/debugmate/errors` | POST |
| `/api/debugmate/metrics` | POST |
| `/api/debugmate/logs` | POST |
| `/api/debugmate/queries` | POST |
| `/api/debugmate/analytics` | POST |
| `/api/debugmate/monitors` | POST |

## Configuration

Publish config (optional):

```bash
php artisan vendor:publish --tag=config --provider="Irabbi360\LaravelDebugMate\DebugMateServiceProvider"
```

Key env vars: `DEBUGMATE_ENABLED`, `DEBUGMATE_API_URL`, `DEBUGMATE_PROJECT_KEY`, `DEBUGMATE_TRACK_*`, `DEBUGMATE_ASYNC_REPORTING`.

### What is never traced

Three exclusion lists keep the SDK from measuring itself. Each is a plain array
in `config/debugmate.php` and supports `*` wildcards.

| Key | Excludes | Why |
| --- | --- | --- |
| `ignore_paths` | HTTP paths | Skips DebugMate's own ingest endpoints. |
| `ignore_commands` | Artisan commands | **Long-running daemons must stay listed.** A daemon fires `CommandStarting` but not `CommandFinished` until it exits, so tracing one keeps a root span open for the life of the process and the worker leaks memory. `queue:work`, `horizon*`, `octane:start` and friends are enforced in code and cannot be re-enabled. |
| `ignore_tables` | Database tables | Stops writing telemetry from generating telemetry. Covers the queue tables, `sessions`, `cache`, and DebugMate's own ingest tables — essential when an app reports to a DebugMate instance sharing its database. |

### Trace limits

Every trace is capped so a single request, job, or bug cannot exhaust memory:

```env
DEBUGMATE_MAX_SPANS=500          # 0 disables the cap
DEBUGMATE_MAX_QUERIES=200        # 0 disables the cap
DEBUGMATE_MAX_PAYLOAD_SIZE=262144
```

Overflow is counted rather than buffered. When a trace is truncated, the metric
payload carries `dropped_spans` / `dropped_queries` in its context so the gap is
visible rather than silent.

### Reporting to a DebugMate instance on the same database

If you self-host DebugMate and point an app at it, give the SDK its own queue
connection (Redis, for example) so telemetry jobs do not write rows into the
database they are measuring. `ignore_tables` covers the query-tracking half of
the loop; a separate connection covers the rest.

## Testing

```bash
composer install
composer test
```

## License

MIT — see [LICENSE.md](LICENSE.md)
