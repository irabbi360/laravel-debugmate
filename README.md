# DebugMate SDK

A comprehensive error tracking, log viewing, and performance monitoring package for Laravel applications.

## Features

- 🚨 **Error Tracking**: Automatically capture and report PHP errors and exceptions
- 📊 **Performance Monitoring**: Track application performance metrics
- 📝 **Log Viewer**: Stream and view application logs in real-time
- 🔐 **API Token Authentication**: Secure token-based communication
- 🎯 **Selective Tracking**: Filter which errors and logs to track
- ⚡ **Async Reporting**: Non-blocking error reporting via queues
- 🏷️ **Tagging**: Tag errors with custom data for better organization

## Installation

```bash
composer require irabbi360/laravel-debugmate
```

## Quick Start

### 1. Register Exception Handler

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    \Irabbi360\LaravelDebugMate\Services\ExceptionHandler::handles($exceptions);
})->create();
```

To manually report an error:

```php
use DebugMate\SDK\Facades\DebugMate;

try {
    // Your code
} catch (Exception $e) {
    DebugMate::reportError($e, [
        'user_id' => auth()->id(),
        'route' => request()->path(),
        'custom_data' => 'any value'
    ]);
}
```

### Performance Monitoring

Track specific operations:

```php
use DebugMate\SDK\Facades\DebugMate;

DebugMate::startMonitoring('database_query');
// ... your code ...
DebugMate::stopMonitoring('database_query', ['query' => 'SELECT...']);
```

### Log Viewer API

Stream logs to the DebugMate app:

```php
use DebugMate\SDK\Facades\DebugMate;

// Automatic - logs are streamed in real-time
// Or manually push logs
DebugMate::log('Channel', 'Log message', 'info', ['context_data']);
```

## API Endpoints

### Report Error
```
POST /api/debugmate/errors
Authorization: Bearer {API_TOKEN}

{
  "project_key": "string",
  "error_type": "string",
  "message": "string",
  "stack_trace": "string",
  "context": object,
  "tags": object,
  "timestamp": "ISO 8601"
}
```

### Stream Performance Metrics
```
POST /api/debugmate/metrics
Authorization: Bearer {API_TOKEN}

{
  "project_key": "string",
  "metric_name": "string",
  "duration_ms": number,
  "context": object,
  "timestamp": "ISO 8601"
}
```

### Stream Logs
```
POST /api/debugmate/logs
Authorization: Bearer {API_TOKEN}

{
  "project_key": "string",
  "channel": "string",
  "message": "string",
  "level": "string",
  "context": object,
  "timestamp": "ISO 8601"
}
```

## Configuration

Edit `config/debugmate.php`:

```php
return [
    'enabled' => env('DEBUGMATE_ENABLED', true),
    'api_url' => env('DEBUGMATE_API_URL'),
    'api_token' => env('DEBUGMATE_API_TOKEN'),
    'project_key' => env('DEBUGMATE_PROJECT_KEY'),
    
    // What to track
    'track_errors' => true,
    'track_logs' => true,
    'track_performance' => true,
    'track_queries' => false,
    
    // Queue configuration
    'queue' => env('QUEUE_CONNECTION', 'sync'),
    'async_reporting' => true,
    
    // Filtering
    'ignore_paths' => ['health', 'ping'],
    'ignore_exceptions' => [],
    'sample_rate' => 1.0, // 0-1, percentage of requests to track
];
```

## Documentation

See full documentation in `/docs` folder or visit [debugmate.app/docs](https://debugmate.app/docs)

## License

MIT

