<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Throwable;
use Illuminate\Support\Facades\Log;
use Irabbi360\LaravelDebugMate\Jobs\ReportErrorJob;

class ErrorTracker
{
    protected ApiClient $apiClient;
    protected array $config;

    public function __construct(ApiClient $apiClient, array $config)
    {
        $this->apiClient = $apiClient;
        $this->config = $config;
    }

    /**
     * Report an error/exception.
     */
    public function reportError(Throwable $exception, array $context = [], ?string $traceId = null): void
    {
        // Check if we should ignore this exception
        if ($this->shouldIgnore($exception)) {
            return;
        }

        // Check sample rate
        if ($this->config['sample_rate'] < 1.0) {
            if (rand(0, 100) / 100 > $this->config['sample_rate']) {
                return;
            }
        }

        $traceId = $traceId ?? $this->generateTraceId();

        $errorData = [
            'trace_id' => $traceId,
            'error_type' => class_basename($exception),
            'message' => $exception->getMessage(),
            'stack_trace' => $exception->getTraceAsString(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'context' => array_merge($this->getDefaultContext(), $context),
            'fingerprint' => $this->generateFingerprint($exception),
        ];

        if ($this->config['async_reporting']) {
            $this->dispatchAsyncReport($errorData);
        } else {
            $this->apiClient->reportError($errorData);
        }
    }

    /**
     * Report a custom error.
     */
    public function reportCustomError(string $type, string $message, array $context = [], array $stackTrace = [], ?string $traceId = null): void
    {
        $traceId = $traceId ?? $this->generateTraceId();

        $errorData = [
            'trace_id' => $traceId,
            'error_type' => $type,
            'message' => $message,
            'stack_trace' => !empty($stackTrace) ? json_encode($stackTrace) : null,
            'context' => array_merge($this->getDefaultContext(), $context),
            'fingerprint' => md5($type . ':' . $message),
        ];

        if ($this->config['async_reporting']) {
            $this->dispatchAsyncReport($errorData);
        } else {
            $this->apiClient->reportError($errorData);
        }
    }

    /**
     * Check if exception should be ignored.
     */
    protected function shouldIgnore(Throwable $exception): bool
    {
        $ignoreExceptions = $this->config['ignore_exceptions'] ?? [];

        foreach ($ignoreExceptions as $ignoreClass) {
            if ($exception instanceof $ignoreClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dispatch async error reporting job.
     */
    protected function dispatchAsyncReport(array $errorData): void
    {
        try {
            dispatch(new ReportErrorJob($errorData))
                ->onQueue('default');
        } catch (\Exception $e) {
            Log::error('Failed to dispatch error reporting job', [
                'error' => $e->getMessage(),
            ]);
            // Fallback to synchronous reporting
            $this->apiClient->reportError($errorData);
        }
    }

    /**
     * Get default context data.
     */
    protected function getDefaultContext(): array
    {
        return array_merge(
            $this->config['context'] ?? [],
            [
                'url' => request()->url() ?? null,
                'method' => request()->method() ?? null,
                'user_agent' => request()->userAgent() ?? null,
                'ip_address' => request()->ip() ?? null,
                'headers' => $this->getSafeHeaders(),
            ]
        );
    }

    /**
     * Get safe headers (filter sensitive data).
     */
    protected function getSafeHeaders(): array
    {
        $headers = request()->headers->all();
        $sensitiveKeys = ['authorization', 'cookie', 'x-api-token', 'x-csrf-token'];

        $safeHeaders = [];
        foreach ($headers as $key => $value) {
            if (!in_array(strtolower($key), $sensitiveKeys)) {
                $safeHeaders[$key] = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return $safeHeaders;
    }

    /**
     * Generate unique trace ID.
     */
    protected function generateTraceId(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }

    /**
     * Generate error fingerprint for grouping.
     */
    protected function generateFingerprint(Throwable $exception): string
    {
        // Create fingerprint from error type, message, and file/line
        $file = $exception->getFile();
        $line = $exception->getLine();
        $type = class_basename($exception);

        return md5($type . ':' . $file . ':' . $line);
    }
}

