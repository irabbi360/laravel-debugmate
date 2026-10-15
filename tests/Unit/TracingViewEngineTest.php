<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use Illuminate\Contracts\View\Engine;
use Irabbi360\LaravelDebugMate\Collectors\ViewCollector;
use Irabbi360\LaravelDebugMate\View\TracingViewEngine;
use PHPUnit\Framework\TestCase;

class TracingViewEngineTest extends TestCase
{
    public function test_get_compiler_proxies_to_underlying_engine(): void
    {
        $compiler = new \stdClass;

        $inner = new class($compiler) implements Engine {
            public function __construct(public object $compiler) {}

            public function get($path, array $data = [])
            {
                return 'ok';
            }

            public function getCompiler()
            {
                return $this->compiler;
            }
        };

        $collector = $this->createMock(ViewCollector::class);
        $collector->expects($this->once())->method('endCurrentViewSpan');

        $engine = new TracingViewEngine($inner, $collector);

        $this->assertSame($compiler, $engine->getCompiler());
        $this->assertSame('ok', $engine->get('/tmp/view.php', []));
    }

    public function test_forget_compiled_or_not_expired_proxies_when_present(): void
    {
        $called = false;

        $inner = new class($called) implements Engine {
            public function __construct(public bool &$called) {}

            public function get($path, array $data = [])
            {
                return '';
            }

            public function forgetCompiledOrNotExpired(): void
            {
                $this->called = true;
            }
        };

        $engine = new TracingViewEngine($inner, $this->createStub(ViewCollector::class));
        $engine->forgetCompiledOrNotExpired();

        $this->assertTrue($called);
    }
}
