<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Irabbi360\LaravelDebugMate\DTO\ErrorDataDTO;
use Irabbi360\LaravelDebugMate\Jobs\ReportErrorJob;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\ErrorTracker;
use Irabbi360\LaravelDebugMate\Services\LogStreamer;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Services\StackTraceParser;
use Irabbi360\LaravelDebugMate\Support\IpAnonymizer;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use Irabbi360\LaravelDebugMate\Tracing\Tracer;
use Mockery;
use RuntimeException;

class ProductionHardeningTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.default', 'stack');
        $app['config']->set('debugmate.track_logs', true);
        $app['config']->set('debugmate.track_analytics', true);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function trackerWith(ApiClient $apiClient): ErrorTracker
    {
        $this->app->instance(ApiClient::class, $apiClient);
        $this->app->forgetInstance(ErrorTracker::class);

        return $this->app->make(ErrorTracker::class);
    }

    public function test_report_error_never_throws_when_the_api_client_fails(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportError')->once()->andThrow(new RuntimeException('network down'));

        $this->trackerWith($apiClient)->reportError(new RuntimeException('original'));

        $this->assertTrue(true, 'reportError must swallow reporting failures');
    }

    public function test_api_client_returns_false_instead_of_throwing_on_connection_failure(): void
    {
        // Port 1 on loopback refuses immediately -> Guzzle ConnectException,
        // which is NOT a RequestException.
        $client = new ApiClient('http://127.0.0.1:1', 'dm_test_key');

        $this->assertFalse($client->reportError(['message' => 'x']));
    }

    public function test_uuid_user_ids_are_sent_as_opaque_identifiers(): void
    {
        $this->actingAs(new GenericUser(['id' => '9b2f3c1e-8f0a-4c6e-9d2b-1a2b3c4d5e6f']));

        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportError')->once()->andReturnUsing(function (array $payload): bool {
            $this->assertSame('9b2f3c1e-8f0a-4c6e-9d2b-1a2b3c4d5e6f', $payload['user_id']);

            return true;
        });

        $this->trackerWith($apiClient)->reportError(new RuntimeException('boom'));
    }

    public function test_user_id_is_optional_and_normalized(): void
    {
        $this->assertNull(ErrorDataDTO::from([])->user_id);
        $this->assertNull(ErrorDataDTO::from(['user_id' => ''])->user_id);
        $this->assertNull(ErrorDataDTO::from(['user_id' => ['not', 'an', 'id']])->user_id);
        $this->assertSame(42, ErrorDataDTO::from(['user_id' => '42'])->user_id);
        $this->assertSame(7, ErrorDataDTO::from(['user_id' => 7])->user_id);
        $this->assertSame(255, mb_strlen(ErrorDataDTO::from(['user_id' => str_repeat('x', 300)])->user_id));
    }

    public function test_code_snippets_follow_the_configured_mode(): void
    {
        $exception = new RuntimeException('boom');
        $parser = new StackTraceParser;

        config(['debugmate.code_snippets' => 'none']);
        foreach ($parser->parseThrowable($exception) as $frame) {
            $this->assertSame([], $frame['code_snippet']);
        }

        config(['debugmate.code_snippets' => 'application']);
        $frames = $parser->parseThrowable($exception);
        $this->assertNotEmpty($frames[0]['code_snippet'], 'the throw point always gets a snippet');
        foreach (array_slice($frames, 1) as $frame) {
            if (! $frame['application_frame']) {
                $this->assertSame([], $frame['code_snippet']);
            }
        }

        config(['debugmate.code_snippets' => 'application', 'debugmate.code_snippet_lines' => 4]);
        $this->assertLessThanOrEqual(5, count($parser->parseThrowable($exception)[0]['code_snippet']));
    }

    public function test_async_reports_use_the_configured_queue(): void
    {
        Queue::fake();
        config([
            'debugmate.async_reporting' => true,
            'debugmate.queue_name' => 'debugmate',
            'debugmate.queue_connection' => 'redis',
        ]);

        $this->trackerWith(Mockery::mock(ApiClient::class))->reportError(new RuntimeException('boom'));

        Queue::assertPushedOn('debugmate', ReportErrorJob::class, function (ReportErrorJob $job) {
            return $job->connection === 'redis';
        });
    }

    public function test_query_exception_messages_do_not_include_binding_values(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportError')->once()->andReturnUsing(function (array $payload): bool {
            $this->assertStringNotContainsString('victim@example.com', $payload['message']);
            $this->assertStringContainsString('select * from users where email = ?', $payload['message']);

            return true;
        });

        $exception = new QueryException(
            'testing',
            'select * from users where email = ?',
            ['victim@example.com'],
            new \PDOException('no such table: users')
        );

        $this->trackerWith($apiClient)->reportError($exception);
    }

    public function test_application_logs_are_streamed_to_the_api(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportLog')->once()->andReturnUsing(function (array $payload): bool {
            $this->assertSame('hello from the app', $payload['message']);
            $this->assertSame('stack', $payload['channel']);
            $this->assertSame('***SANITIZED***', $payload['context']['api_key']);

            return true;
        });

        $this->app->instance(LogStreamer::class, new LogStreamer($apiClient, config('debugmate')));

        Log::info('hello from the app', ['api_key' => 'abc']);
    }

    public function test_analytics_does_not_break_requests_without_a_user_agent(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportAnalytics')->andReturn(true);
        $this->app->instance(ApiClient::class, $apiClient);

        Route::get('/no-ua', fn () => 'ok');

        $this->withHeaders(['User-Agent' => ''])->get('/no-ua')->assertOk();
    }

    public function test_traceparent_is_validated_and_keeps_the_upstream_span(): void
    {
        $this->assertNull(Tracer::fromTraceParent('00-<script>alert(1)</script>xxxxxxxxxxx-0123456789abcdef-01'));
        $this->assertNull(Tracer::fromTraceParent('00-00000000000000000000000000000000-0123456789abcdef-01'));

        $tracer = Tracer::fromTraceParent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

        $this->assertNotNull($tracer);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $tracer->getTraceId());
        $this->assertSame('00f067aa0ba902b7', $tracer->startSpan('root')->toArray()['parent_span_id']);
    }

    public function test_ip_anonymizer_handles_ipv4_and_ipv6(): void
    {
        $this->assertSame('203.0.113.0', IpAnonymizer::anonymize('203.0.113.42'));
        $this->assertSame('2001:db8:85a3::', IpAnonymizer::anonymize('2001:db8:85a3::8a2e:370:7334'));
        $this->assertSame('not-an-ip', IpAnonymizer::anonymize('not-an-ip'));
    }

    public function test_performance_payload_redacts_string_bindings(): void
    {
        $monitor = new PerformanceMonitor(Mockery::mock(ApiClient::class), config('debugmate'));
        $monitor->startCommand('demo');
        $monitor->appendQuery('select * from users where email = ? and id = ?', 1.0, ['a@b.c', 5]);

        $this->assertSame(['***', 5], $monitor->getQueries()[0]['bindings']);
    }
}
