<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Irabbi360\LaravelDebugMate\Collectors\CommandCollector;
use Irabbi360\LaravelDebugMate\Collectors\QueryCollector;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Regression tests for the worker memory leak.
 *
 * A daemon command opened a trace that was never flushed, so every span and
 * query accumulated for the lifetime of the process.
 */
class DaemonTraceLeakTest extends TestCase
{
    #[DataProvider('daemonCommands')]
    public function test_daemon_commands_never_open_a_trace(string $command): void
    {
        /** @var PerformanceMonitor $monitor */
        $monitor = $this->app->make(PerformanceMonitor::class);

        (new CommandCollector($monitor))->register();

        Event::dispatch(new CommandStarting($command, new ArrayInput([]), new NullOutput));

        $this->assertFalse(
            $monitor->hasActiveTrace(),
            "{$command} is a long-running daemon and must not open a trace"
        );
    }

    public static function daemonCommands(): array
    {
        return [
            ['queue:work'],
            ['queue:listen'],
            ['horizon'],
            ['horizon:work'],
            ['schedule:work'],
            ['octane:start'],
            ['pail'],
            ['debugmate:verify'],
        ];
    }

    public function test_short_lived_commands_are_still_traced(): void
    {
        /** @var PerformanceMonitor $monitor */
        $monitor = $this->app->make(PerformanceMonitor::class);

        (new CommandCollector($monitor))->register();

        Event::dispatch(new CommandStarting('migrate', new ArrayInput([]), new NullOutput));

        $this->assertTrue($monitor->hasActiveTrace());

        Event::dispatch(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

        $this->assertFalse($monitor->hasActiveTrace());
    }

    public function test_daemon_list_cannot_be_disabled_by_config(): void
    {
        config()->set('debugmate.ignore_commands', []);

        /** @var PerformanceMonitor $monitor */
        $monitor = $this->app->make(PerformanceMonitor::class);

        (new CommandCollector($monitor))->register();

        Event::dispatch(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));

        $this->assertFalse($monitor->hasActiveTrace());
    }

    public function test_queries_outside_a_trace_are_not_recorded(): void
    {
        /** @var PerformanceMonitor $monitor */
        $monitor = $this->app->make(PerformanceMonitor::class);

        (new QueryCollector($monitor))->register();

        // No startRequest()/startJob() - this is the idle worker polling.
        DB::select('select 1 as value');

        $this->assertSame(0, $monitor->getQueryCount());
        $this->assertSame(0, $monitor->getTracer()->getDroppedSpanCount());
        $this->assertCount(0, $monitor->getTracer()->getSpans());
    }
}
