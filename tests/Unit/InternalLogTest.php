<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use Irabbi360\LaravelDebugMate\Support\InternalLog;
use PHPUnit\Framework\TestCase;

class InternalLogTest extends TestCase
{
    public function test_internal_context_key_constant(): void
    {
        $this->assertSame('_debugmate_internal', InternalLog::CONTEXT_KEY);
    }

    public function test_api_client_uses_internal_log(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ApiClient.php'
        );

        $this->assertStringContainsString('InternalLog::', $source);
        $this->assertStringNotContainsString('use Illuminate\Support\Facades\Log;', $source);
    }

    public function test_exception_handler_respects_track_errors_flag(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ExceptionHandler.php'
        );

        $this->assertStringContainsString("config('debugmate.track_errors'", $source);
    }
}
