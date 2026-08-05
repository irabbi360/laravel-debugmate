<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

use Irabbi360\LaravelDebugMate\Support\InternalLog;
use RuntimeException;

trait RetriesApiReporting
{
    public function tries(): int
    {
        return max(1, (int) config('debugmate.job_retries', 3));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('debugmate.job_backoff_seconds', [10, 30, 60]);

        return is_array($backoff) ? array_map('intval', $backoff) : [10, 30, 60];
    }

    public function timeout(): int
    {
        return (int) config('debugmate.job_timeout', 30);
    }

    protected function assertReported(bool $success, string $type): void
    {
        if (! $success) {
            throw new RuntimeException("DebugMate {$type} report failed");
        }
    }

    public function failed(?\Throwable $exception): void
    {
        InternalLog::error('DebugMate: Report job failed after retries', [
            'job' => static::class,
            'error' => $exception?->getMessage(),
        ]);
    }
}
