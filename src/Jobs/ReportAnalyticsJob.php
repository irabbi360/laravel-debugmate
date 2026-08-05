<?php

namespace Irabbi360\LaravelDebugMate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Irabbi360\LaravelDebugMate\Concerns\RetriesApiReporting;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

class ReportAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesApiReporting, SerializesModels;

    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(ApiClient $apiClient): void
    {
        $this->assertReported(
            $apiClient->reportAnalytics($this->payload),
            'analytics'
        );
    }
}
