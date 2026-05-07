# DebugMate SDK Changelog

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

