<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use Irabbi360\LaravelDebugMate\DTO\QueryDataDTO;
use PHPUnit\Framework\TestCase;

class QueryDataDTOTest extends TestCase
{
    public function test_from_detects_select_operation(): void
    {
        $dto = QueryDataDTO::from([
            'sql' => 'SELECT * FROM users WHERE id = ?',
            'time_ms' => 12.5,
            'bindings' => [1],
        ]);

        $this->assertSame('SELECT', $dto->operation);
        $this->assertSame(1, $dto->bindings_count);
        $this->assertFalse($dto->isSlow(1000));
    }

    public function test_is_slow_respects_threshold(): void
    {
        $dto = QueryDataDTO::from([
            'sql' => 'UPDATE users SET name = ?',
            'time_ms' => 1500,
            'bindings' => ['Ada'],
        ]);

        $this->assertSame('UPDATE', $dto->operation);
        $this->assertTrue($dto->isSlow(1000));
    }

    public function test_to_array_shape(): void
    {
        $dto = QueryDataDTO::from([
            'sql' => 'DELETE FROM posts',
            'time_ms' => 3.0,
        ]);

        $this->assertSame([
            'sql' => 'DELETE FROM posts',
            'time_ms' => 3.0,
            'bindings_count' => 0,
            'operation' => 'DELETE',
            'bindings' => [],
            'database' => 'default',
        ], $dto->toArray());
    }
}
