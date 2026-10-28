<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Support\ReportBuffer;
use Irabbi360\LaravelDebugMate\Tests\Concerns\RecordsApiRequests;
use Irabbi360\LaravelDebugMate\Tests\TestCase;

/**
 * Octane is not installed here: binding "octane" before the provider boots and
 * dispatching its events by name is exactly what the provider listens for.
 */
class OctaneDeferredReportingTest extends TestCase
{
    use RecordsApiRequests;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->instance('octane', new \stdClass);
    }

    protected function tearDown(): void
    {
        ReportBuffer::forget();
        ApiClient::resetCircuit();

        parent::tearDown();
    }

    private function octaneEvent(string $name): void
    {
        event("Laravel\\Octane\\Events\\{$name}");
    }

    public function test_reports_during_an_octane_request_are_batched_after_it_terminates(): void
    {
        $this->app->instance(ApiClient::class, $this->client([new Response(200)]));
        $client = $this->app->make(ApiClient::class);

        $this->octaneEvent('RequestReceived');
        $client->reportError(['message' => 'Boom']);
        $client->reportLog(['message' => 'Logged']);

        $this->assertSame([], $this->sentPaths(), 'Nothing may be sent while the request runs');

        $this->octaneEvent('RequestTerminated');

        $this->assertSame(['/api/debugmate/batch'], $this->sentPaths());
        $this->assertSame(['errors', 'logs'], array_column($this->sentBody(0)['items'], 'type'));
    }

    public function test_each_octane_request_gets_its_own_batch(): void
    {
        $this->app->instance(ApiClient::class, $this->client([new Response(200), new Response(200)]));
        $client = $this->app->make(ApiClient::class);

        $this->octaneEvent('RequestReceived');
        $client->reportError(['message' => 'First']);
        $this->octaneEvent('RequestTerminated');

        $this->octaneEvent('RequestReceived');
        $client->reportError(['message' => 'Second']);
        $this->octaneEvent('RequestTerminated');

        $this->assertSame(['/api/debugmate/batch', '/api/debugmate/batch'], $this->sentPaths());
        $this->assertSame('First', $this->sentBody(0)['items'][0]['payload']['message']);
        $this->assertCount(1, $this->sentBody(1)['items']);
        $this->assertSame('Second', $this->sentBody(1)['items'][0]['payload']['message']);
    }

    public function test_octane_tasks_are_batched_too(): void
    {
        $this->app->instance(ApiClient::class, $this->client([new Response(200)]));
        $client = $this->app->make(ApiClient::class);

        $this->octaneEvent('TaskReceived');
        $client->reportLog(['message' => 'From a task']);
        $this->assertSame([], $this->sentPaths());

        $this->octaneEvent('TaskTerminated');

        $this->assertSame(['/api/debugmate/batch'], $this->sentPaths());
    }

    public function test_reports_between_octane_requests_are_sent_immediately(): void
    {
        $this->app->instance(ApiClient::class, $this->client([new Response(201)]));

        $this->octaneEvent('RequestReceived');
        $this->octaneEvent('RequestTerminated');

        $this->app->make(ApiClient::class)->reportError(['message' => 'Worker boot error']);

        $this->assertSame(['/api/debugmate/errors'], $this->sentPaths());
    }
}
