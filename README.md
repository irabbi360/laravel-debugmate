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

Supports PHP 8.2+ and Laravel 11, 12 and 13.

### Queue

With `DEBUGMATE_ASYNC_REPORTING=true`, reports are sent from queued jobs. Put them
on their own queue so a burst of errors never delays your application's jobs:

```env
DEBUGMATE_QUEUE=debugmate            # queue name (default: "default")
DEBUGMATE_QUEUE_CONNECTION=redis     # optional; defaults to the app's connection
```

Remember to run a worker for it, e.g. `php artisan queue:work --queue=default,debugmate`.

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

### Data privacy

Reports are scrubbed before they leave your app:

- Keys containing `password`, `token`, `secret`, `api_key`, `cookie`, `session`,
  `authorization`, `credit_card`, `signature`, … (and exact keys such as `pin`,
  `otp`, `pwd`) are replaced with `***SANITIZED***`. Values that look like
  credentials (bcrypt/argon hashes, JWTs, `Bearer …`, Stripe/GitHub/AWS keys) are
  masked too. Add your own with the `sensitive_keys` config array.
- Sensitive query-string parameters are masked in every URL that is reported.
- String query bindings are replaced with `***`; numbers, booleans and nulls are
  kept.
- Stack-frame string arguments are reported as `string(<length>)`, never their value.
- `QueryException` messages are rebuilt from the placeholder SQL, so bound values
  never appear in the error message.
- The session id is never sent (only a one-way hash); cookies are reported by name only.

```env
DEBUGMATE_REDACT_QUERY_BINDINGS=true  # false = send raw bindings
DEBUGMATE_SEND_USER_PII=true          # false = omit the user's name and email
DEBUGMATE_ANONYMIZE_IPS=true          # analytics: IPv4 -> a.b.c.0, IPv6 -> /48
DEBUGMATE_GEOLOCATION_ENDPOINT=http://ip-api.com/json/
```

`user_id` is optional and identifies the user in *your* app — DebugMate never
looks it up. Integer keys are sent as integers; UUID / ULID / other string keys
are sent as strings (max 255 chars); guests send `null`.

Source code is attached to stack frames for your application's files only (not
`vendor/`). Change with `DEBUGMATE_CODE_SNIPPETS=application|all|none` and
`DEBUGMATE_CODE_SNIPPET_LINES=30`.

Use `https://` for `DEBUGMATE_API_URL` in production — the project key is sent on
every request. `php artisan debugmate:verify` warns when it is not.

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
