<?php

namespace Irabbi360\LaravelDebugMate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Irabbi360\LaravelDebugMate\Concerns\RetriesApiReporting;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

class RegisterMonitorsJob implements ShouldQueue
{
    use Queueable, RetriesApiReporting, SerializesModels;

    public function __construct(
        protected array $payload,
    ) {}

    public function handle(ApiClient $apiClient): void
    {
        $this->assertReported(
            $apiClient->registerMonitors($this->payload),
            'monitors'
        );
    }
}
