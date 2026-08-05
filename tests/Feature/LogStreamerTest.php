<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Irabbi360\LaravelDebugMate\Facades\DebugMate;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\LogStreamer;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use Mockery;

class LogStreamerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_stream_log_respects_configured_log_channels(): void
    {
        config([
            'debugmate.track_logs' => true,
            'debugmate.log_channels' => ['stack', 'single'],
        ]);

        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->expects($this->once())
            ->method('reportLog')
            ->willReturn(true);

        $this->app->instance(ApiClient::class, $apiClient);
        $this->app->forgetInstance(LogStreamer::class);

        /** @var LogStreamer $streamer */
        $streamer = $this->app->make(LogStreamer::class);

        $streamer->streamLog('tracked', 'info', ['channel' => 'stack']);
        $streamer->streamLog('ignored', 'info', ['channel' => 'custom']);
    }

    public function test_facade_log_delegates_to_log_streamer(): void
    {
        config([
            'debugmate.log_channels' => ['payments'],
        ]);

        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('reportLog')
            ->once()
            ->andReturnUsing(function (array $payload): bool {
                $this->assertSame('payments', $payload['channel'] ?? null);
                $this->assertSame('Payment captured', $payload['message'] ?? null);

                return true;
            });

        $this->app->instance(ApiClient::class, $apiClient);
        $this->app->forgetInstance(LogStreamer::class);

        DebugMate::log('payments', 'Payment captured', 'info', ['order_id' => 42]);
    }
}
