<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use PHPUnit\Framework\TestCase;

class RetriesApiReportingTest extends TestCase
{
    public function test_report_jobs_use_retry_trait(): void
    {
        $jobs = [
            'ReportErrorJob.php',
            'ReportLogJob.php',
            'ReportMetricJob.php',
            'ReportAnalyticsJob.php',
            'RegisterMonitorsJob.php',
        ];

        foreach ($jobs as $job) {
            $source = file_get_contents(dirname(__DIR__, 2).'/src/Jobs/'.$job);

            $this->assertStringContainsString('RetriesApiReporting', $source, $job);
            $this->assertStringContainsString('assertReported', $source, $job);
        }
    }

    public function test_retry_trait_defines_tries_and_backoff(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Concerns/RetriesApiReporting.php'
        );

        $this->assertStringContainsString('function tries(): int', $source);
        $this->assertStringContainsString('function backoff(): array', $source);
        $this->assertStringContainsString('function failed', $source);
    }
}
