<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ApiClientEndpointsTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ApiClient.php'
        );
    }

    public function test_report_methods_target_expected_paths(): void
    {
        $expected = [
            '/api/debugmate/errors',
            '/api/debugmate/metrics',
            '/api/debugmate/logs',
            '/api/debugmate/queries',
            '/api/debugmate/analytics',
            '/api/debugmate/verify-token',
            '/api/debugmate/project',
        ];

        foreach ($expected as $path) {
            $this->assertStringContainsString($path, $this->source, "Missing path: {$path}");
        }

        $this->assertDoesNotMatchRegularExpression(
            "/get\\('\\/api\\/debugmate\\/verify'\\s*,/",
            $this->source,
            'Old /verify path must not remain'
        );

        $this->assertStringNotContainsString(
            "projects/'.\$this->debugmateKey",
            $this->source
        );
    }

    public function test_headers_include_debugmate_key_header(): void
    {
        $this->assertStringContainsString("'X-DebugMate-Key'", $this->source);
        $this->assertStringContainsString("'Content-Type' => 'application/json'", $this->source);
    }
}
