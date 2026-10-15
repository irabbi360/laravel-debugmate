<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Irabbi360\LaravelDebugMate\Concerns\AsyncDispatch;
use Irabbi360\LaravelDebugMate\Concerns\CaptureRequestData;
use Irabbi360\LaravelDebugMate\Concerns\CaptureSanitization;
use Irabbi360\LaravelDebugMate\Concerns\CaptureStackTrace;
use Irabbi360\LaravelDebugMate\DTO\ErrorDataDTO;
use Irabbi360\LaravelDebugMate\Support\InternalLog;
use Irabbi360\LaravelDebugMate\Support\ReportingGuard;
use Throwable;

class ErrorTracker
{
    use CaptureStackTrace;
    use CaptureSanitization;
    use CaptureRequestData;
    use AsyncDispatch;

    protected ApiClient $apiClient;
    protected StackTraceParser $stackTraceParser;
    protected ContextCollector $contextCollector;
    protected array $config;

    public function __construct(
        ApiClient $apiClient,
        StackTraceParser $stackTraceParser,
        ContextCollector $contextCollector,
        array $config
    ) {
        $this->apiClient = $apiClient;
        $this->stackTraceParser = $stackTraceParser;
        $this->contextCollector = $contextCollector;
        $this->config = $config;
    }

    /**
     * Report an error/exception with complete context using DTO.
     *
     * Runs inside Laravel's exception handler, which does not guard report
     * callbacks: anything thrown here would replace the original exception
     * and skip the app's own logging. So this method never throws.
     */
    public function reportError(Throwable $exception, array $context = [], ?string $traceId = null): void
    {
        ReportingGuard::run(function () use ($exception, $context, $traceId) {
            try {
                $this->buildAndReportError($exception, $context, $traceId);
            } catch (Throwable $e) {
                InternalLog::error('DebugMate: Failed to report exception', [
                    'exception' => get_class($exception),
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    protected function buildAndReportError(Throwable $exception, array $context, ?string $traceId): void
    {
        if ($this->shouldIgnore($exception) || ! $this->passesSampleRate()) {
            return;
        }

        $traceId = $traceId ?? Str::uuid()->toString();

        $frames = $this->stackTraceParser->toArray($this->stackTraceParser->parseThrowable($exception));
        $metadata = $this->captureExceptionMetadata($exception);

        $errorData = ErrorDataDTO::from(array_merge([
            'trace_id' => $traceId,
            'error_type' => $metadata['type'],
            'message' => $this->safeExceptionMessage($exception),
            'file' => $metadata['file'],
            'line' => $metadata['line'],
            'code' => is_numeric($metadata['code']) ? (int) $metadata['code'] : 0,
            'stack_trace' => json_encode($frames, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null,
            'frames' => $frames,
            'fingerprint' => $this->generateErrorFingerprint($exception),
            'context' => $this->sanitizeData($this->collectCompleteContext($context)),
            'source' => $this->detectSource(),
        ], $this->requestAttributes()));

        $this->reportFromDTO($errorData);
    }

    /**
     * Report from ErrorDataDTO.
     */
    public function reportFromDTO(ErrorDataDTO $dto): void
    {
        $data = $dto->toArray();

        if ($this->config['async_reporting'] ?? false) {
            $this->dispatchErrorReport($data);
        } else {
            $this->apiClient->reportError($data);
        }
    }

    /**
     * Report a custom error with complete context.
     */
    public function reportCustomError(string $type, string $message, array $context = [], array $stackTrace = [], ?string $traceId = null): void
    {
        ReportingGuard::run(function () use ($type, $message, $context, $stackTrace, $traceId) {
            try {
                $errorData = ErrorDataDTO::from(array_merge([
                    'trace_id' => $traceId ?? Str::uuid()->toString(),
                    'error_type' => $type,
                    'message' => (string) $this->sanitizeMessage($message),
                    'file' => $context['file'] ?? 'unknown',
                    'line' => $context['line'] ?? 0,
                    'code' => $context['code'] ?? 0,
                    'stack_trace' => ! empty($stackTrace) ? json_encode($stackTrace, JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                    'frames' => $stackTrace,
                    'context' => $this->sanitizeData($this->collectCompleteContext($context)),
                    'source' => $this->detectSource(),
                ], $this->requestAttributes()));

                $this->reportFromDTO($errorData);
            } catch (Throwable $e) {
                InternalLog::error('DebugMate: Failed to report custom error', [
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Exception message with secrets and personal data removed.
     *
     * QueryException messages embed the SQL with its bound values, and driver
     * messages repeat offending values ("Duplicate entry 'a@b.c' ..."), so the
     * message is rebuilt from the placeholder SQL plus a scrubbed driver message.
     * Other messages (e.g. Guzzle's, which include the full request URL) are
     * scrubbed for URLs, key=value secrets and credential-shaped strings.
     */
    protected function safeExceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $driverMessage = $exception->getPrevious()?->getMessage() ?? 'Database error';

            return $this->sanitizeDatabaseMessage($driverMessage).' (SQL: '.$this->sanitizeQuery($exception->getSql()).')';
        }

        if ($exception instanceof \PDOException) {
            return (string) $this->sanitizeDatabaseMessage($exception->getMessage());
        }

        return (string) $this->sanitizeMessage($exception->getMessage());
    }

    /**
     * Request-level attributes, safe in CLI / queue contexts.
     */
    protected function requestAttributes(): array
    {
        $request = app()->bound('request') ? request() : null;

        if (! $request || app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['user_id' => $this->safeUserId()];
        }

        return [
            'user_id' => $this->safeUserId(),
            'url' => $this->sanitizeRequestUrl($request, false),
            'user_agent' => $request->userAgent(),
            'ip_address' => $this->sanitizeIp($request->ip()),
            'method' => $request->method(),
        ];
    }

    /**
     * auth()->id() can hit the database; when the error IS a database outage
     * that would throw again.
     */
    protected function safeUserId(): mixed
    {
        try {
            return auth()->id();
        } catch (Throwable) {
            return null;
        }
    }

    protected function passesSampleRate(): bool
    {
        $rate = (float) ($this->config['sample_rate'] ?? 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        return (mt_rand() / mt_getrandmax()) <= $rate;
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
     * Collect complete context from the request.
     */
    protected function collectCompleteContext(array $additionalContext = []): array
    {
        if (! app()->bound('request') || ! request()) {
            return $additionalContext;
        }

        try {
            $collectedContext = $this->contextCollector->collect(request());
        } catch (Throwable $e) {
            $collectedContext = ['context_error' => $e->getMessage()];
        }

        return array_merge($collectedContext, $additionalContext);
    }

    /**
     * Dispatch async error reporting job.
     */
    protected function dispatchAsyncReport(array $errorData): void
    {
        $this->dispatchErrorReport($errorData);
    }

    /**
     * Get default context data (kept for backwards compatibility).
     */
    protected function getDefaultContext(): array
    {
        return array_merge(
            $this->config['context'] ?? [],
            $this->captureRequestData(),
        );
    }

    /**
     * Detect the source of the error (http, queue, cli, etc).
     */
    protected function detectSource(): string
    {
        if (app()->runningUnitTests()) {
            return 'test';
        }

        if (app()->runningInConsole()) {
            return 'cli';
        }

        return 'http';
    }

    /**
     * Generate unique trace ID.
     */
    protected function generateTraceId(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Generate error fingerprint for grouping (kept for backwards compatibility).
     */
    protected function generateFingerprint(Throwable $exception): string
    {
        return $this->generateErrorFingerprint($exception);
    }
}
