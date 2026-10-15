<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

use Irabbi360\LaravelDebugMate\Jobs\ReportErrorJob;
use Irabbi360\LaravelDebugMate\Support\InternalLog;
use Irabbi360\LaravelDebugMate\Jobs\ReportLogJob;
use Irabbi360\LaravelDebugMate\Jobs\ReportMetricJob;
use Irabbi360\LaravelDebugMate\Jobs\ReportAnalyticsJob;
use Irabbi360\LaravelDebugMate\Support\PayloadLimiter;

/**
 * AsyncDispatch - Trait for handling asynchronous job dispatching
 *
 * Provides methods for dispatching data reporting jobs to the queue.
 */
trait AsyncDispatch
{
    /**
     * Dispatch error reporting job.
     */
    public function dispatchErrorReport(array $errorData): void
    {
        try {
            $this->dispatchToQueue(new ReportErrorJob($this->limitPayload($errorData)));
        } catch (\Throwable $e) {
            InternalLog::error('DebugMate: Failed to dispatch error reporting job', [
                'error' => $e->getMessage(),
            ]);
            $this->handleDispatchFailure('error', $errorData);
        }
    }

    /**
     * Dispatch log reporting job.
     */
    public function dispatchLogReport(array $logData): void
    {
        try {
            $this->dispatchToQueue(new ReportLogJob($this->limitPayload($logData)));
        } catch (\Throwable $e) {
            InternalLog::error('DebugMate: Failed to dispatch log reporting job', [
                'error' => $e->getMessage(),
            ]);
            $this->handleDispatchFailure('log', $logData);
        }
    }

    /**
     * Dispatch metric reporting job.
     */
    public function dispatchMetricReport(array $metricData): void
    {
        try {
            $this->dispatchToQueue(new ReportMetricJob($this->limitPayload($metricData)));
        } catch (\Throwable $e) {
            InternalLog::error('DebugMate: Failed to dispatch metric reporting job', [
                'error' => $e->getMessage(),
            ]);
            $this->handleDispatchFailure('metric', $metricData);
        }
    }

    /**
     * Dispatch analytics reporting job.
     */
    public function dispatchAnalyticsReport(array $analyticsData): void
    {
        try {
            $this->dispatchToQueue(new ReportAnalyticsJob($this->limitPayload($analyticsData)));
        } catch (\Throwable $e) {
            InternalLog::error('DebugMate: Failed to dispatch analytics reporting job', [
                'error' => $e->getMessage(),
            ]);
            $this->handleDispatchFailure('analytics', $analyticsData);
        }
    }

    /**
     * Shrink a payload to the configured byte limit before it is queued.
     *
     * ApiClient also trims before sending, but that is too late for a database
     * queue: the untrimmed array is serialized into `jobs.payload` first, so an
     * oversized trace is written to the database whether or not it is ever
     * delivered.
     */
    protected function limitPayload(array $payload): array
    {
        try {
            return (new PayloadLimiter)->fit($payload);
        } catch (\Throwable $e) {
            InternalLog::debug('DebugMate: Failed to limit payload before dispatch', [
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }
    }

    /**
     * Dispatch a DebugMate job on the configured connection and queue.
     */
    protected function dispatchToQueue(object $job, int $delaySeconds = 0): void
    {
        $pending = dispatch($job)->onQueue($this->getQueueName());

        if ($connection = config('debugmate.queue_connection')) {
            $pending->onConnection($connection);
        }

        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * Queue name for DebugMate jobs (debugmate.queue_name).
     */
    protected function getQueueName(): string
    {
        $queue = config('debugmate.queue_name');

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    /**
     * Handle dispatch failure - fallback to sync reporting.
     * Override in service class to implement specific behavior.
     */
    protected function handleDispatchFailure(string $type, array $data): void
    {
        InternalLog::warning("DebugMate: Job dispatch failed for {$type}, consider enabling sync queue");
    }

    /**
     * Dispatch with delay.
     */
    public function dispatchWithDelay(string $jobClass, array $data, int $seconds): void
    {
        try {
            $this->dispatchToQueue(new $jobClass($this->limitPayload($data)), $seconds);
        } catch (\Throwable $e) {
            InternalLog::error('DebugMate: Failed to dispatch delayed job', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Batch dispatch multiple jobs.
     */
    public function dispatchBatch(string $jobClass, array $items, string $type = 'default'): void
    {
        try {
            foreach ($items as $item) {
                $this->dispatchToQueue(new $jobClass($this->limitPayload($item)));
            }
        } catch (\Throwable $e) {
            InternalLog::error('DebugMate: Failed to batch dispatch jobs', [
                'error' => $e->getMessage(),
                'count' => count($items),
            ]);
        }
    }

    /**
     * Check if async reporting is enabled.
     * Override in service class to use config values.
     */
    protected function isAsyncReportingEnabled(): bool
    {
        return config('debugmate.async_reporting', false);
    }
}

