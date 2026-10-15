<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Irabbi360\LaravelDebugMate\DTO\LogDataDTO;
use Irabbi360\LaravelDebugMate\Support\InternalLog;
use Irabbi360\LaravelDebugMate\Support\ReportingGuard;
use Irabbi360\LaravelDebugMate\Support\SafeTrace;
use Irabbi360\LaravelDebugMate\Concerns\CaptureStackTrace;
use Irabbi360\LaravelDebugMate\Concerns\CaptureSanitization;
use Irabbi360\LaravelDebugMate\Concerns\CaptureRequestData;
use Irabbi360\LaravelDebugMate\Concerns\AsyncDispatch;

class LogStreamer
{
    use CaptureStackTrace;
    use CaptureSanitization;
    use CaptureRequestData;
    use AsyncDispatch;

    protected ApiClient $apiClient;
    protected array $config;
    protected array $logBuffer = [];

    public function __construct(ApiClient $apiClient, array $config)
    {
        $this->apiClient = $apiClient;
        $this->config = $config;
    }

    /**
     * Stream a log message using DTO.
     */
    public function streamLog(string $message, string $level, array $context = [], ?string $traceId = null, ?string $source = null): void
    {
        if (! $this->shouldTrackLog($context)) {
            return;
        }

        ReportingGuard::run(function () use ($message, $level, $context, $traceId, $source) {
            try {
                $this->buildAndStreamLog($message, $level, $context, $traceId, $source);
            } catch (\Throwable $e) {
                InternalLog::error('DebugMate: Failed to stream log', ['error' => $e->getMessage()]);
            }
        });
    }

    protected function buildAndStreamLog(string $message, string $level, array $context, ?string $traceId, ?string $source): void
    {
        // Extract exception data if present
        $stackTrace = null;
        $exceptionClass = null;
        $exceptionFile = null;
        $exceptionLine = null;

        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            // Not getTraceAsString(): it embeds string arguments (passwords, tokens).
            $stackTrace = SafeTrace::asString($exception);
            $exceptionClass = get_class($exception);
            $exceptionFile = $exception->getFile();
            $exceptionLine = $exception->getLine();
        }

        // Sanitize context - remove non-serializable objects
        $sanitizedContext = $this->sanitizeForSerialization($context);

        // Create log DTO
        $logData = LogDataDTO::from([
            'trace_id' => $traceId,
            'channel' => $sanitizedContext['channel'] ?? 'default',
            'message' => (string) $this->sanitizeMessage($message),
            'level' => $level,
            'source' => $source ?? $this->detectSource(),
            'context' => $this->sanitizeContext($sanitizedContext),
            'stack_trace' => $stackTrace,
            'exception_class' => $exceptionClass,
            'file' => $exceptionFile,
            'line' => $exceptionLine,
            'user_id' => $this->safeUserId(),
            'url' => $this->currentUrl(),
        ]);

        $this->reportFromDTO($logData);
    }

    /**
     * Report from LogDataDTO.
     */
    public function reportFromDTO(LogDataDTO $dto): void
    {
        $data = $dto->toArray();

        if ($this->config['async_reporting'] ?? false) {
            $this->dispatchLogReport($data);
        } else {
            $this->apiClient->reportLog($data);
        }
    }

    /**
     * Manual log reporting.
     */
    public function log(string $channel, string $message, string $level = 'info', array $context = [], ?string $traceId = null): void
    {
        if (! $this->shouldTrackLog(['channel' => $channel])) {
            return;
        }

        $logData = [
            'trace_id' => $traceId,
            'channel' => $channel,
            'message' => (string) $this->sanitizeMessage($message),
            'level' => $level,
            'source' => $this->detectSource(),
            'context' => array_merge($this->getDefaultContext(), $context),
            'timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
        ];

        ReportingGuard::run(function () use ($logData) {
            try {
                $logData['context'] = $this->sanitizeContext($this->sanitizeForSerialization($logData['context']));

                if ($this->config['async_reporting'] ?? false) {
                    $this->dispatchAsyncLog($logData);
                } else {
                    $this->apiClient->reportLog($logData);
                }
            } catch (\Throwable $e) {
                InternalLog::error('DebugMate: Failed to send log', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Buffer logs for batch reporting.
     */
    public function buffer(string $message, string $level, array $context = []): void
    {
        $this->logBuffer[] = [
            'message' => $message,
            'level' => $level,
            'context' => $context,
            'timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Flush buffered logs.
     */
    public function flush(): void
    {
        if (empty($this->logBuffer)) {
            return;
        }

        // Send all buffered logs
        $this->log('batch', 'Batch log report', 'info', [
            'logs' => $this->logBuffer,
            'count' => count($this->logBuffer),
        ]);

        $this->logBuffer = [];
    }

    /**
     * Get recent logs from file.
     */
    public function getRecentLogs(string $channel = 'stack', int $lines = 100): array
    {
        $logPath = $this->logPathFor($channel);

        if ($logPath === null || ! file_exists($logPath)) {
            return [];
        }

        $file = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recent = array_slice($file, -$lines);

        return array_map(function ($line) {
            return $this->parseLaravelLogLine($line);
        }, $recent);
    }

    /**
     * Stream logs from a specific file.
     */
    public function streamFromFile(string $filePath, int $startLine = 0): void
    {
        // Only files inside storage/logs may be shipped: this method sends the
        // file's contents to the DebugMate server, so an arbitrary path (e.g.
        // .env) passed in by mistake or from user input must be refused.
        $realPath = realpath($filePath);
        $logsDir = realpath(storage_path('logs'));

        if ($realPath === false || $logsDir === false
            || ! str_starts_with($realPath, $logsDir.DIRECTORY_SEPARATOR)
            || ! is_file($realPath)) {
            InternalLog::warning('DebugMate: Refusing to stream a file outside storage/logs or a missing file.');

            return;
        }

        $filePath = $realPath;

        $file = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logLines = array_slice($file, $startLine);

        foreach ($logLines as $line) {
            $logData = $this->parseLaravelLogLine($line);
            if ($logData) {
                $this->streamLog(
                    $logData['message'] ?? $line,
                    $logData['level'] ?? 'info',
                    $logData['context'] ?? []
                );
            }
        }
    }

    /**
     * Watch and stream logs in real-time.
     */
    public function watch(string $channel = 'stack', ?callable $callback = null): void
    {
        $logPath = $this->logPathFor($channel);

        if ($logPath === null || ! file_exists($logPath)) {
            InternalLog::warning('DebugMate: Log file not found for channel: '.$channel);
            return;
        }

        $lastSize = filesize($logPath);
        $lastCheck = time();

        while (true) {
            sleep(1);

            if (time() - $lastCheck < 1) {
                continue;
            }

            // filesize() is cached by PHP; without this the loop never sees growth.
            clearstatcache(true, $logPath);

            if (! file_exists($logPath)) {
                $lastCheck = time();
                continue;
            }

            $currentSize = filesize($logPath);

            // File was rotated or truncated: start reading from the beginning.
            if ($currentSize < $lastSize) {
                $lastSize = 0;
            }

            if ($currentSize > $lastSize) {
                $file = fopen($logPath, 'r');
                fseek($file, $lastSize);
                $newLogs = fread($file, $currentSize - $lastSize);
                fclose($file);

                $lines = explode(PHP_EOL, $newLogs);
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $logData = $this->parseLaravelLogLine($line);
                        if ($logData) {
                            if ($callback) {
                                call_user_func($callback, $logData);
                            }
                            $this->streamLog(
                                $logData['message'] ?? $line,
                                $logData['level'] ?? 'info',
                                $logData['context'] ?? []
                            );
                        }
                    }
                }

                $lastSize = $currentSize;
            }

            $lastCheck = time();
        }
    }

    /**
     * Check if log should be tracked.
     */
    protected function shouldTrackLog(array $context): bool
    {
        $channel = $context['channel'] ?? 'default';

        // Check if channel is in tracked channels
        if (! empty($this->config['log_channels'])) {
            return in_array($channel, $this->config['log_channels'], true);
        }

        return true;
    }

    /**
     * Parse Laravel log line.
     */
    protected function parseLaravelLogLine(string $line): ?array
    {
        // Match Laravel log format: [timestamp] channel.level: message
        if (preg_match('/\[(.+?)]\s+(.+?)\.(\w+):\s+(.+)/', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'channel' => $matches[2],
                'level' => $matches[3],
                'message' => $matches[4],
            ];
        }

        return null;
    }

    /**
     * Dispatch async log reporting job.
     */
    protected function dispatchAsyncLog(array $logData): void
    {
        $this->dispatchLogReport($logData);
    }

    /**
     * Get default context data.
     */
    protected function getDefaultContext(): array
    {
        return array_merge(
            $this->config['context'] ?? [],
            [
                'url' => $this->currentUrl(),
                'user_id' => $this->safeUserId(),
            ]
        );
    }

    /**
     * Resolve a channel's log file, refusing names that could escape storage/logs.
     */
    protected function logPathFor(string $channel): ?string
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $channel) || str_contains($channel, '..')) {
            return null;
        }

        return storage_path('logs/'.$channel.'.log');
    }

    protected function currentUrl(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        return app()->bound('request') ? $this->sanitizeRequestUrl(request(), false) : null;
    }

    protected function safeUserId(): mixed
    {
        try {
            return auth()->id();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Detect the source of the log (http, queue, cli, etc).
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
}
