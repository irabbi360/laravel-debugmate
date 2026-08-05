<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\ErrorTracker;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use Mockery;
use RuntimeException;

class ErrorTrackerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_report_error_sends_sanitized_payload_to_api(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportError')
            ->once()
            ->andReturnUsing(function (array $payload): bool {
                $this->assertSame('***SANITIZED***', $payload['context']['password'] ?? null);
                $this->assertSame('RuntimeException', $payload['error_type'] ?? null);

                return true;
            });

        $this->app->instance(ApiClient::class, $apiClient);
        $this->app->forgetInstance(ErrorTracker::class);

        /** @var ErrorTracker $tracker */
        $tracker = $this->app->make(ErrorTracker::class);

        $tracker->reportError(new RuntimeException('boom'), [
            'password' => 'secret-value',
        ]);
    }

    public function test_report_error_respects_ignore_exceptions(): void
    {
        config(['debugmate.ignore_exceptions' => [RuntimeException::class]]);

        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->expects($this->never())->method('reportError');

        $this->app->instance(ApiClient::class, $apiClient);
        $this->app->forgetInstance(ErrorTracker::class);

        /** @var ErrorTracker $tracker */
        $tracker = $this->app->make(ErrorTracker::class);
        $tracker->reportError(new RuntimeException('ignored'));
    }
}
