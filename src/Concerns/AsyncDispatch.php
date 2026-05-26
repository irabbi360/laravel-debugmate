<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

use Illuminate\Support\Facades\Log;
use Irabbi360\LaravelDebugMate\Jobs\ReportErrorJob;
use Irabbi360\LaravelDebugMate\Jobs\ReportLogJob;
use Irabbi360\LaravelDebugMate\Jobs\ReportMetricJob;
use Irabbi360\LaravelDebugMate\Jobs\ReportAnalyticsJob;

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
            dispatch(new ReportErrorJob($errorData))
                ->onQueue($this->getQueueName('errors'));
        } catch (\Exception $e) {
            Log::error('DebugMate: Failed to dispatch error reporting job', [
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
            dispatch(new ReportLogJob($logData))
                ->onQueue($this->getQueueName('logs'));
        } catch (\Exception $e) {
            Log::error('DebugMate: Failed to dispatch log reporting job', [
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
            dispatch(new ReportMetricJob($metricData))
                ->onQueue($this->getQueueName('metrics'));
        } catch (\Exception $e) {
            Log::error('DebugMate: Failed to dispatch metric reporting job', [
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
            dispatch(new ReportAnalyticsJob($analyticsData))
                ->onQueue($this->getQueueName('analytics'));
        } catch (\Exception $e) {
            Log::error('DebugMate: Failed to dispatch analytics reporting job', [
                'error' => $e->getMessage(),
            ]);
            $this->handleDispatchFailure('analytics', $analyticsData);
        }
    }

    /**
     * Get queue name for a report type.
     * Override in service class to use config values.
     */
    protected function getQueueName(string $type): string
    {
        return 'default'; //config('queue.default', 'default');
    }

    /**
     * Handle dispatch failure - fallback to sync reporting.
     * Override in service class to implement specific behavior.
     */
    protected function handleDispatchFailure(string $type, array $data): void
    {
        Log::warning("DebugMate: Job dispatch failed for {$type}, consider enabling sync queue");
    }

    /**
     * Dispatch with delay.
     */
    public function dispatchWithDelay(string $jobClass, array $data, int $seconds): void
    {
        try {
            dispatch(new $jobClass($data))
                ->onQueue($this->getQueueName('default'))
                ->delay(now()->addSeconds($seconds));
        } catch (\Exception $e) {
            Log::error('DebugMate: Failed to dispatch delayed job', [
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
            $jobs = array_map(fn($item) => new $jobClass($item), $items);
            dispatch($jobs);
        } catch (\Exception $e) {
            Log::error('DebugMate: Failed to batch dispatch jobs', [
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

