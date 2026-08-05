<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ErrorTrackerSanitizationTest extends TestCase
{
    public function test_report_error_sanitizes_context(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ErrorTracker.php'
        );

        $this->assertStringContainsString(
            "'context' => \$this->sanitizeData(\$completedContext)",
            $source
        );
    }
}
