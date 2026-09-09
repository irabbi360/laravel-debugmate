<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\ErrorTracker;
use Irabbi360\LaravelDebugMate\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_package_services_are_registered(): void
    {
        $this->assertTrue($this->app->bound(ApiClient::class));
        $this->assertTrue($this->app->bound(ErrorTracker::class));
        $this->assertSame('dm_test_key', config('debugmate.debugmate_key'));
    }

    public function test_debugmate_config_is_merged(): void
    {
        $this->assertTrue(config('debugmate.enabled'));
        $this->assertSame(3, config('debugmate.job_retries'));
        $this->assertSame(262144, config('debugmate.max_payload_size'));
    }
}
