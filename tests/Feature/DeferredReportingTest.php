<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Support\ReportBuffer;
use Irabbi360\LaravelDebugMate\Tests\Concerns\RecordsApiRequests;
use Irabbi360\LaravelDebugMate\Tests\TestCase;

class DeferredReportingTest extends TestCase
{
    use RecordsApiRequests;

    protected function tearDown(): void
    {
        ReportBuffer::reset();
        ApiClient::resetCircuit();

        parent::tearDown();
    }

    public function test_web_request_reports_wait_and_go_out_in_one_batch(): void
    {
        ReportBuffer::reset(forceDeferral: true);
        $client = $this->client([new Response(200)]);

        $this->assertTrue($client->reportError(['message' => 'Boom']));
        $this->assertTrue($client->reportLog(['message' => 'Logged']));
        $this->assertSame([], $this->sentPaths(), 'Nothing may be sent during the request');

        $client->flushDeferred();

        $this->assertSame(['/api/debugmate/batch'], $this->sentPaths());
        $items = $this->sentBody(0)['items'];
        $this->assertSame(['errors', 'logs'], array_column($items, 'type'));
        $this->assertSame('Boom', $items[0]['payload']['message']);
        $this->assertArrayHasKey('timestamp', $items[0]['payload']);
    }

    public function test_app_terminate_sends_the_buffered_batch(): void
    {
        ReportBuffer::reset(forceDeferral: true);
        $this->app->instance(ApiClient::class, $this->client([new Response(200)]));

        $this->app->make(ApiClient::class)->reportError(['message' => 'Boom']);
        $this->assertSame([], $this->sentPaths());

        $this->app->terminate();

        $this->assertSame(['/api/debugmate/batch'], $this->sentPaths());
    }

    public function test_console_and_queue_reports_are_sent_immediately(): void
    {
        ReportBuffer::reset();
        $client = $this->client([new Response(201)]);

        $this->assertTrue($client->reportError(['message' => 'Boom']));

        $this->assertSame(['/api/debugmate/errors'], $this->sentPaths());
    }

    public function test_reports_after_the_flush_are_sent_directly(): void
    {
        ReportBuffer::reset(forceDeferral: true);
        $client = $this->client([new Response(201)]);

        $client->flushDeferred();
        $client->reportError(['message' => 'Late']);

        $this->assertSame(['/api/debugmate/errors'], $this->sentPaths());
    }

    public function test_older_servers_without_a_batch_endpoint_get_each_report_individually(): void
    {
        ReportBuffer::reset(forceDeferral: true);
        $client = $this->client([new Response(404), new Response(201), new Response(201)]);

        $client->reportError(['message' => 'Boom']);
        $client->reportAnalytics(['type' => 'page_view']);
        $client->flushDeferred();

        $this->assertSame(['/api/debugmate/batch', '/api/debugmate/errors', '/api/debugmate/analytics'], $this->sentPaths());
    }

    public function test_large_batches_are_split_and_the_buffer_is_capped(): void
    {
        config(['debugmate.buffer_limit' => 150]);
        ReportBuffer::reset(forceDeferral: true);
        $client = $this->client([new Response(200), new Response(200)]);

        for ($i = 0; $i < 160; $i++) {
            $client->reportLog(['message' => "Line {$i}"]);
        }

        $client->flushDeferred();

        $this->assertSame(['/api/debugmate/batch', '/api/debugmate/batch'], $this->sentPaths());
        $this->assertCount(100, $this->sentBody(0)['items']);
        $this->assertCount(50, $this->sentBody(1)['items']);
    }

    public function test_octane_events_are_ignored_when_octane_is_not_installed(): void
    {
        $this->app->instance(ApiClient::class, $this->client([new Response(201)]));

        event('Laravel\\Octane\\Events\\RequestReceived');
        $this->app->make(ApiClient::class)->reportError(['message' => 'Boom']);

        $this->assertSame(['/api/debugmate/errors'], $this->sentPaths());
    }

    public function test_monitor_sync_is_never_deferred(): void
    {
        ReportBuffer::reset(forceDeferral: true);
        $client = $this->client([new Response(200)]);

        $client->registerMonitors(['monitors' => []]);

        $this->assertSame(['/api/debugmate/monitors'], $this->sentPaths());
    }
}
