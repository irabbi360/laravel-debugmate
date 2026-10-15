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
        if ($success) {
            return;
        }

        // Retry while attempts remain. On the last attempt, give up quietly
        // instead of throwing: a thrown job is copied, payload and all, into
        // failed_jobs, where report data (URLs, user ids, context) would be
        // retained indefinitely.
        $attempts = method_exists($this, 'attempts') ? (int) $this->attempts() : 1;

        if ($attempts < $this->tries()) {
            throw new RuntimeException("DebugMate {$type} report failed");
        }

        InternalLog::warning("DebugMate: {$type} report dropped after {$attempts} attempt(s)", [
            'job' => static::class,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        InternalLog::error('DebugMate: Report job failed after retries', [
            'job' => static::class,
            'error' => $exception?->getMessage(),
        ]);
    }
}
