<?php

namespace Irabbi360\LaravelDebugMate\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;
use Irabbi360\LaravelDebugMate\Services\ApiClient;

class ReportErrorJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected array $errorData;

    public function __construct(array $errorData)
    {
        $this->errorData = $errorData;
    }

    /**
     * Execute the job.
     */
    public function handle(ApiClient $apiClient): void
    {
        $apiClient->reportError($this->errorData);
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function timeout(): int
    {
        return 30; // 30 seconds
    }
}

