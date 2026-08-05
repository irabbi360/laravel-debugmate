<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use PHPUnit\Framework\TestCase;

class QueryCollectorDoubleRecordTest extends TestCase
{
    public function test_query_collector_uses_append_query_not_record_query(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Collectors/QueryCollector.php'
        );

        $this->assertStringContainsString('appendQuery', $source);
        $this->assertStringNotContainsString('$this->monitor->recordQuery', $source);
    }
}
