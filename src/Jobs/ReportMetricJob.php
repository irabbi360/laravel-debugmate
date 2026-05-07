<?php

namespace Irabbi360\LaravelDebugMate\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

class ReportMetricJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected array $metricData;

    public function __construct(array $metricData)
    {
        $this->metricData = $metricData;
    }

    /**
     * Execute the job.
     */
    public function handle(ApiClient $apiClient): void
    {
        $apiClient->reportMetric($this->metricData);
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function timeout(): int
    {
        return 30; // 30 seconds
    }
}

