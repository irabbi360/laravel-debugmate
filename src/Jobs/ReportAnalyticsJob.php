<?php

namespace Irabbi360\LaravelDebugMate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

class ReportAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(ApiClient $apiClient): void
    {
        try {
            $apiClient->reportAnalytics($this->payload);
        } catch (\Throwable $e) {
            \Log::error('DebugMate: Failed to report analytics', [
                'error' => $e->getMessage(),
                'payload' => $this->payload,
            ]);

            throw $e;
        }
    }
}

