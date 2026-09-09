<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Irabbi360\LaravelDebugMate\Collectors\QueryCollector;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression tests for the self-ingest amplification loop.
 *
 * When an app reports to a DebugMate instance backed by the same database,
 * writing telemetry produced queries that were themselves recorded as
 * telemetry, enlarging the next payload without bound.
 */
class SelfIngestLoopTest extends TestCase
{
    private QueryCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collector = new QueryCollector($this->app->make(PerformanceMonitor::class));
    }

    #[DataProvider('ignoredQueries')]
    public function test_infrastructure_and_telemetry_queries_are_ignored(string $sql): void
    {
        $this->assertTrue($this->collector->shouldIgnoreQuery($sql), $sql);
    }

    public static function ignoredQueries(): array
    {
        return [
            'queue insert' => ['insert into `jobs` (`queue`, `payload`) values (?, ?)'],
            'queue poll' => ['select * from `jobs` where `queue` = ? order by `id` asc limit 1'],
            'queue delete' => ['delete from `jobs` where `id` = ?'],
            'failed jobs' => ['insert into `failed_jobs` (`payload`) values (?)'],
            'sessions' => ['update `sessions` set `payload` = ? where `id` = ?'],
            'cache' => ['select * from `cache` where `key` = ?'],
            'metrics ingest' => ['insert into `performance_monitors` (`spans`) values (?)'],
            'error ingest' => ['insert into `track_errors` (`message`) values (?)'],
            'log ingest' => ['insert into `track_logs` (`message`) values (?)'],
            'analytics wildcard' => ['insert into `debugmate_analytics_page_views` (`url`) values (?)'],
            'uptime checks' => ['insert into `uptime_checks` (`status`) values (?)'],
            'schema qualified' => ['insert into `debugmate`.`jobs` (`queue`) values (?)'],
            'join' => ['select * from `users` inner join `jobs` on `jobs`.`id` = `users`.`id`'],
        ];
    }

    #[DataProvider('trackedQueries')]
    public function test_application_queries_are_still_tracked(string $sql): void
    {
        $this->assertFalse($this->collector->shouldIgnoreQuery($sql), $sql);
    }

    public static function trackedQueries(): array
    {
        return [
            'select users' => ['select * from `users` where `id` = ?'],
            'insert orders' => ['insert into `orders` (`total`) values (?)'],
            'update projects' => ['update `projects` set `name` = ? where `id` = ?'],
            'no table' => ['select 1 as value'],
            // Substring of an ignored name must not match.
            'jobs_archive' => ['select * from `jobs_archive` where `id` = ?'],
            'user_sessions' => ['select * from `user_sessions` where `id` = ?'],
        ];
    }

    public function test_ignored_tables_produce_no_spans_or_queries(): void
    {
        // Schema setup happens before tracing starts so only the reads below
        // are measured.
        DB::statement('create table jobs (id integer primary key, queue varchar(255))');
        DB::statement('create table orders (id integer primary key)');

        /** @var PerformanceMonitor $monitor */
        $monitor = $this->app->make(PerformanceMonitor::class);
        $monitor->startRequest();

        (new QueryCollector($monitor))->register();

        DB::select('select * from jobs');

        $this->assertSame(0, $monitor->getQueryCount(), 'queue table reads must not be tracked');

        DB::select('select * from orders');

        $this->assertSame(1, $monitor->getQueryCount(), 'application reads must still be tracked');
    }
}
