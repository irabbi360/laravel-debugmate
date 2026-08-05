<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use Irabbi360\LaravelDebugMate\Support\PayloadLimiter;
use PHPUnit\Framework\TestCase;

class PayloadLimiterTest extends TestCase
{
    public function test_leaves_small_payload_unchanged(): void
    {
        $limiter = new PayloadLimiter(1024);
        $payload = ['message' => 'ok', 'frames' => []];

        $this->assertSame($payload, $limiter->fit($payload));
    }

    public function test_truncates_oversized_error_payload(): void
    {
        $limiter = new PayloadLimiter(2048);
        $payload = [
            'message' => str_repeat('x', 500),
            'stack_trace' => str_repeat('trace', 500),
            'frames' => array_fill(0, 200, [
                'file' => '/app/Example.php',
                'line' => 10,
                'function' => 'handle',
            ]),
            'context' => [
                'note' => str_repeat('context', 200),
            ],
        ];

        $result = $limiter->fit($payload);

        $this->assertTrue($result['_payload_truncated'] ?? false);
        $this->assertLessThanOrEqual(2048, $limiter->byteSize($result));
        $this->assertArrayNotHasKey('stack_trace', $result);
        $this->assertLessThanOrEqual(50, count($result['frames']));
    }

    public function test_truncates_metric_queries_and_spans(): void
    {
        $limiter = new PayloadLimiter(4096);
        $payload = [
            'endpoint' => '/api/users',
            'spans' => array_fill(0, 300, ['name' => 'db.query', 'duration_ms' => 12]),
            'all_queries' => array_fill(0, 300, ['sql' => 'select * from users', 'time_ms' => 5]),
            'slow_queries' => array_fill(0, 300, ['sql' => 'select * from orders', 'time_ms' => 1200]),
        ];

        $result = $limiter->fit($payload);

        $this->assertTrue($result['_payload_truncated'] ?? false);
        $this->assertLessThanOrEqual(4096, $limiter->byteSize($result));
        $this->assertLessThanOrEqual(100, count($result['spans']));
        $this->assertLessThanOrEqual(50, count($result['all_queries']));
    }
}
