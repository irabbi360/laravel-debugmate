<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Irabbi360\LaravelDebugMate\Jobs\ReportErrorJob;
use Irabbi360\LaravelDebugMate\DTO\ErrorDataDTO;
use Irabbi360\LaravelDebugMate\Concerns\CaptureStackTrace;
use Irabbi360\LaravelDebugMate\Concerns\CaptureSanitization;
use Irabbi360\LaravelDebugMate\Concerns\CaptureRequestData;
use Irabbi360\LaravelDebugMate\Concerns\AsyncDispatch;

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

        $traceId = $traceId ?? \Illuminate\Support\Str::uuid()->toString();

        // Parse detailed stack frames using trait
        $frames = $this->stackTraceParser->parseThrowable($exception);;
        $metadata = $this->captureExceptionMetadata($exception);

        // Collect complete context information
        $completedContext = $this->collectCompleteContext($context);

        // Create error DTO
        $errorData = ErrorDataDTO::from([
            'trace_id' => $traceId,
            'error_type' => $metadata['type'],
            'message' => $metadata['message'],
            'file' => $metadata['file'],
            'line' => $metadata['line'],
            'code' => $metadata['code'],
            'stack_trace' => json_encode($this->stackTraceParser->toArray($frames)),
            'frames' => $this->stackTraceParser->toArray($frames),
            'fingerprint' => $this->generateErrorFingerprint($exception),
            'context' => $completedContext,
            'source' => $this->detectSource(),
            'user_id' => auth()->id() ?? null,
            'url' => request()->url() ?? null,
            'user_agent' => request()->userAgent() ?? null,
            'ip_address' => request()->ip() ?? null,
            'method' => request()->method() ?? null,
        ]);

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
        $traceId = $traceId ?? \Illuminate\Support\Str::uuid()->toString();

        // Collect complete context information
        $completedContext = $this->collectCompleteContext($context);

        // Create error DTO
        $errorData = ErrorDataDTO::from([
            'trace_id' => $traceId,
            'error_type' => $type,
            'message' => $message,
            'file' => $context['file'] ?? 'unknown',
            'line' => $context['line'] ?? 0,
            'code' => $context['code'] ?? 0,
            'stack_trace' => !empty($stackTrace) ? json_encode($stackTrace) : null,
            'frames' => $stackTrace,
            'context' => $this->sanitizeData($completedContext),
            'source' => $this->detectSource(),
            'user_id' => auth()->id() ?? null,
            'url' => request()->url() ?? null,
            'user_agent' => request()->userAgent() ?? null,
            'ip_address' => request()->ip() ?? null,
            'method' => request()->method() ?? null,
        ]);

        $this->reportFromDTO($errorData);
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
        if (!request()) {
            return $additionalContext;
        }

        // Get all context data from ContextCollector
        $collectedContext = $this->contextCollector->collect(request());

        // Merge with additional context provided
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
        $requestData = $this->captureRequestData();

        return array_merge(
            $this->config['context'] ?? [],
            $requestData,
        );
    }

    /**
     * Detect the source of the error (http, queue, cli, etc).
     */
    protected function detectSource(): string
    {
        if (app()->runningInConsole()) {
            return 'cli';
        }

        if (app()->runningUnitTests()) {
            return 'test';
        }

        return 'http';
    }

    /**
     * Generate unique trace ID.
     */
    protected function generateTraceId(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }

    /**
     * Generate error fingerprint for grouping (kept for backwards compatibility).
     */
    protected function generateFingerprint(Throwable $exception): string
    {
        return $this->generateErrorFingerprint($exception);
    }
}

