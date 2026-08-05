# DebugMate SDK

Error tracking, log streaming, and performance monitoring for Laravel apps.

## Features

- Error tracking with stack frames and fingerprinting
- Performance monitoring with OpenTelemetry-style spans
- Log streaming
- Request analytics
- Query / job / command / view / HTTP / Livewire collectors
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

### Verify setup

```bash
php artisan debugmate:verify
```

## Auth

SDK sends `X-DebugMate-Key: {DEBUGMATE_PROJECT_KEY}` on every request.

| Endpoint | Method |
|----------|--------|
| `/api/debugmate/verify-token` | GET |
| `/api/debugmate/project` | GET |
| `/api/debugmate/errors` | POST |
| `/api/debugmate/metrics` | POST |
| `/api/debugmate/logs` | POST |
| `/api/debugmate/queries` | POST |
| `/api/debugmate/analytics` | POST |

## Configuration

Publish config (optional):

```bash
php artisan vendor:publish --tag=config --provider="Irabbi360\LaravelDebugMate\DebugMateServiceProvider"
```

Key env vars: `DEBUGMATE_ENABLED`, `DEBUGMATE_API_URL`, `DEBUGMATE_PROJECT_KEY`, `DEBUGMATE_TRACK_*`, `DEBUGMATE_ASYNC_REPORTING`.

## Testing

```bash
composer install
composer test
```

## License

MIT — see [LICENSE.md](LICENSE.md)
