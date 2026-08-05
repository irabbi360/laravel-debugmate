<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Irabbi360\LaravelDebugMate\Collectors\QueryCollector;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Tests\TestCase;

class QueryCollectorTest extends TestCase
{
    public function test_each_query_is_recorded_once(): void
    {
        /** @var PerformanceMonitor $monitor */
        $monitor = $this->app->make(PerformanceMonitor::class);
        $monitor->startRequest();

        (new QueryCollector($monitor))->register();

        DB::select('select 1 as value');
        DB::select('select 2 as value');

        $this->assertSame(2, $monitor->getQueryCount());

        $summary = $monitor->getSummary();
        $dbSpans = array_values(array_filter(
            $summary['spans'],
            fn (array $span) => ($span['name'] ?? null) === 'db.query'
        ));

        $this->assertCount(2, $dbSpans);
    }

    public function test_manual_record_query_still_creates_span_and_payload_entry(): void
    {
        /** @var PerformanceMonitor $monitor */
        $monitor = $this->app->make(PerformanceMonitor::class);
        $monitor->startRequest();

        $monitor->recordQuery('select * from users', 12.5, []);

        $this->assertSame(1, $monitor->getQueryCount());
        $this->assertCount(1, array_filter(
            $monitor->getSummary()['spans'],
            fn (array $span) => ($span['name'] ?? null) === 'db.query'
        ));
    }
}
