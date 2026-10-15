<?php

namespace Irabbi360\LaravelDebugMate\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Irabbi360\LaravelDebugMate\Concerns\RetriesApiReporting;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

class ReportErrorJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, RetriesApiReporting, SerializesModels;

    protected array $errorData;

    public function __construct(array $errorData)
    {
        $this->errorData = $errorData;
    }

    public function handle(ApiClient $apiClient): void
    {
        $this->assertReported(
            $apiClient->reportError($this->errorData),
            'error'
        );
    }
}
