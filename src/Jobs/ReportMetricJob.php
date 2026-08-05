<?php

namespace Irabbi360\LaravelDebugMate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Irabbi360\LaravelDebugMate\Concerns\RetriesApiReporting;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

class ReportMetricJob implements ShouldQueue
{
    use Queueable, RetriesApiReporting, SerializesModels;

    protected array $metricData;

    public function __construct(array $metricData)
    {
        $this->metricData = $metricData;
    }

    public function handle(ApiClient $apiClient): void
    {
        $this->assertReported(
            $apiClient->reportMetric($this->metricData),
            'metric'
        );
    }
}
