<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use Irabbi360\LaravelDebugMate\Tracing\Tracer;

class TraceLimitsTest extends TestCase
{
    public function test_spans_are_capped_and_overflow_is_counted(): void
    {
        config()->set('debugmate.limits.max_spans', 10);

        $tracer = new Tracer;

        for ($i = 0; $i < 25; $i++) {
            $span = $tracer->startSpan('test.span');
            $tracer->endSpan($span);
        }

        $this->assertCount(10, $tracer->getSpans());
        $this->assertSame(15, $tracer->getDroppedSpanCount());
        $this->assertSame(15, $tracer->toArray()['dropped_spans']);
    }

    public function test_queries_are_capped_and_overflow_is_counted(): void
    {
        config()->set('debugmate.limits.max_queries', 5);

        /** @var PerformanceMonitor $monitor */
        $monitor = $this->app->make(PerformanceMonitor::class);
        $monitor->startRequest();

        for ($i = 0; $i < 20; $i++) {
            $monitor->appendQuery("select {$i}", 1.0);
        }

        $this->assertSame(5, $monitor->getQueryCount());
        $this->assertSame(15, $monitor->getDroppedQueryCount());
    }

    public function test_starting_a_new_trace_resets_the_dropped_counter(): void
    {
        config()->set('debugmate.limits.max_queries', 2);

        /** @var PerformanceMonitor $monitor */
        $monitor = $this->app->make(PerformanceMonitor::class);
        $monitor->startRequest();

        foreach (range(1, 6) as $i) {
            $monitor->appendQuery("select {$i}", 1.0);
        }

        $this->assertSame(4, $monitor->getDroppedQueryCount());

        $monitor->startRequest();

        $this->assertSame(0, $monitor->getDroppedQueryCount());
        $this->assertSame(0, $monitor->getQueryCount());
    }

    public function test_zero_max_spans_disables_the_cap(): void
    {
        $tracer = new Tracer(null, Tracer::TRACE_FLAG_SAMPLED, 0);

        for ($i = 0; $i < 50; $i++) {
            $tracer->endSpan($tracer->startSpan('test.span'));
        }

        $this->assertCount(50, $tracer->getSpans());
        $this->assertSame(0, $tracer->getDroppedSpanCount());
    }
}
