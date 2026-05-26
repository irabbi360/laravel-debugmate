<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Support\Facades\Log;
use Irabbi360\LaravelDebugMate\Jobs\ReportLogJob;
use Irabbi360\LaravelDebugMate\DTO\LogDataDTO;
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
        // Extract exception data if present
        $stackTrace = null;
        $exceptionClass = null;
        $exceptionFile = null;
        $exceptionLine = null;

        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            $stackTrace = $exception->getTraceAsString();
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
            'message' => $message,
            'level' => $level,
            'source' => $source ?? $this->detectSource(),
            'context' => $this->sanitizeContext($sanitizedContext),
            'stack_trace' => $stackTrace,
            'exception_class' => $exceptionClass,
            'file' => $exceptionFile,
            'line' => $exceptionLine,
            'user_id' => auth()->id() ?? null,
            'url' => request()->url() ?? null,
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
        $logData = [
            'trace_id' => $traceId,
            'channel' => $channel,
            'message' => $message,
            'level' => $level,
            'source' => $this->detectSource(),
            'context' => array_merge($this->getDefaultContext(), $context),
        ];

        if ($this->config['async_reporting']) {
            $this->dispatchAsyncLog($logData);
        } else {
            $this->apiClient->reportLog($logData);
        }
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
            'timestamp' => now()->toIso8601String(),
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
        $logPath = storage_path('logs/'.$channel.'.log');

        if (!file_exists($logPath)) {
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
        if (!file_exists($filePath)) {
            Log::warning('Log file not found: '.$filePath);
            return;
        }

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
    public function watch(string $channel = 'stack', callable $callback = null): void
    {
        $logPath = storage_path('logs/'.$channel.'.log');

        if (!file_exists($logPath)) {
            Log::warning('Log file not found: '.$logPath);
            return;
        }

        $lastSize = filesize($logPath);
        $lastCheck = time();

        while (true) {
            sleep(1);

            if (time() - $lastCheck < 1) {
                continue;
            }

            $currentSize = filesize($logPath);

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
        if (!empty($this->config['log_channels'])) {
            $shouldTrack = in_array($channel, $this->config['log_channels']);
            // Debug logging
            if (config('app.debug')) {
                \Illuminate\Support\Facades\Log::debug('DebugMate: Channel check - channel=' . $channel . ', tracked=' . ($shouldTrack ? 'yes' : 'no') . ', configured=' . json_encode($this->config['log_channels']));
            }
            return $shouldTrack;
        }

        return true;
    }

    /**
     * Sanitize context to remove objects that can't be serialized for queuing.
     */
    protected function sanitizeForSerialization(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if ($value === null) {
                $sanitized[$key] = null;
            } elseif (is_scalar($value)) {
                // Scalars are always safe
                $sanitized[$key] = $value;
            } elseif (is_array($value)) {
                // Recursively process arrays
                $sanitized[$key] = $this->sanitizeForSerialization($value);
            } elseif ($value instanceof \JsonSerializable) {
                // JsonSerializable objects can be serialized
                $sanitized[$key] = $value->jsonSerialize();
            } elseif ($value instanceof \Throwable) {
                // Convert exception to string representation (can't serialize objects)
                $sanitized[$key] = [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            } elseif (is_object($value)) {
                // Skip other objects - they can't be serialized
                // Skip closures, streams, resources, etc.
                continue;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize context to remove sensitive data.
     */
    protected function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'token', 'api_key', 'secret', 'authorization'];
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $sanitized[$key] = '***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
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
                'url' => request()->url() ?? null,
                'user_id' => auth()->id() ?? null,
            ]
        );
    }

    /**
     * Detect the source of the log (http, queue, cli, etc).
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
}





