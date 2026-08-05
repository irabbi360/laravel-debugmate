<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PackagePolishTest extends TestCase
{
    public function test_composer_uses_stable_minimum_stability(): void
    {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('stable', $composer['minimum-stability']);
    }

    public function test_facade_exposes_log_method(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Facades/DebugMate.php'
        );

        $this->assertStringContainsString('public static function log(', $source);
        $this->assertStringContainsString('LogStreamer::class', $source);
    }
}
