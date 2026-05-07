<?php

namespace Irabbi360\LaravelDebugMate\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

class ReportLogJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected array $logData;

    public function __construct(array $logData)
    {
        $this->logData = $logData;
    }

    /**
     * Execute the job.
     */
    public function handle(ApiClient $apiClient): void
    {
        $apiClient->reportLog($this->logData);
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function timeout(): int
    {
        return 30; // 30 seconds
    }
}

