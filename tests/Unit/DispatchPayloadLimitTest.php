<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The payload must be trimmed before the job is constructed, not only inside
 * ApiClient - a database queue writes the serialized job to `jobs.payload`
 * before ApiClient is ever reached.
 */
class DispatchPayloadLimitTest extends TestCase
{
    public function test_dispatchers_trim_payloads_before_queueing(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Concerns/AsyncDispatch.php'
        );

        $this->assertStringContainsString('PayloadLimiter', $source);

        foreach (['ReportErrorJob', 'ReportLogJob', 'ReportMetricJob', 'ReportAnalyticsJob'] as $job) {
            $this->assertStringContainsString(
                "new {$job}(\$this->limitPayload(",
                $source,
                "{$job} is queued without trimming its payload"
            );
        }
    }
}
