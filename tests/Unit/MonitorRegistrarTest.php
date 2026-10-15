<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\MonitorRegistrar;
use PHPUnit\Framework\TestCase;

class MonitorRegistrarTest extends TestCase
{
    public function test_resolve_urls_normalizes_and_dedupes(): void
    {
        $api = $this->createStub(ApiClient::class);
        $registrar = new MonitorRegistrar($api, [
            'enabled' => true,
            'track_monitors' => true,
            'monitors' => [
                'urls' => [
                    'https://Example.com/',
                    'https://example.com',
                    'api.example.com',
                ],
                'include_app_url' => false,
            ],
        ]);

        $urls = $registrar->resolveUrls();

        $this->assertContains('https://Example.com', $urls);
        $this->assertContains('https://api.example.com', $urls);
        $this->assertCount(2, $urls);
    }

    public function test_build_monitors_includes_flags(): void
    {
        $api = $this->createStub(ApiClient::class);
        $registrar = new MonitorRegistrar($api, [
            'monitors' => [
                'urls' => ['https://example.com'],
                'include_app_url' => false,
                'check_ssl' => true,
                'check_domain' => false,
                'check_uptime' => true,
                'expected_status' => 204,
                'timeout_seconds' => 15,
            ],
        ]);

        $monitors = $registrar->buildMonitors();

        $this->assertSame([
            [
                'url' => 'https://example.com',
                'check_ssl' => true,
                'check_domain' => false,
                'check_uptime' => true,
                'expected_status' => 204,
                'timeout_seconds' => 15,
            ],
        ], $monitors);
    }
}
