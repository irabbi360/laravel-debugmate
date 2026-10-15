<?php

namespace Irabbi360\LaravelDebugMate\Tests\Unit;

use Irabbi360\LaravelDebugMate\Concerns\CaptureSanitization;
use Irabbi360\LaravelDebugMate\Services\StackTraceParser;
use Irabbi360\LaravelDebugMate\Tests\TestCase;

class ErrorTrackerSanitizationTest extends TestCase
{
    protected function sanitizer(): object
    {
        return new class
        {
            use CaptureSanitization;
        };
    }

    public function test_sensitive_keys_are_masked_but_innocent_keys_are_kept(): void
    {
        $result = $this->sanitizer()->sanitizeData([
            'password' => 'hunter2',
            'current_password' => 'hunter2',
            'pin' => '1234',
            'php-auth-pw' => 'basic-pw',
            'nested' => ['access_token' => 'abc', 'author_id' => 7, 'authenticated' => true],
            'spinner' => 'loading',
        ]);

        $this->assertSame('***SANITIZED***', $result['password']);
        $this->assertSame('***SANITIZED***', $result['current_password']);
        $this->assertSame('***SANITIZED***', $result['pin']);
        $this->assertSame('***SANITIZED***', $result['php-auth-pw']);
        $this->assertSame('***SANITIZED***', $result['nested']['access_token']);
        $this->assertSame(7, $result['nested']['author_id']);
        $this->assertTrue($result['nested']['authenticated']);
        $this->assertSame('loading', $result['spinner']);
    }

    public function test_credential_looking_values_are_masked(): void
    {
        $result = $this->sanitizer()->sanitizeData([
            'hash' => '$2y$12$abcdefghijklmnopqrstuuJ0K9b3xQx0Zl8vY2tq6l3m2p1o0n9m8',
            'header' => 'Bearer abc.def.ghi',
            'stripe' => 'sk_live_abcdefghijklmnop',
            'name' => 'Ada Lovelace',
        ]);

        $this->assertSame('***SANITIZED***', $result['hash']);
        $this->assertSame('***SANITIZED***', $result['header']);
        $this->assertSame('***SANITIZED***', $result['stripe']);
        $this->assertSame('Ada Lovelace', $result['name']);
    }

    public function test_sensitive_query_parameters_are_masked_in_urls(): void
    {
        $url = $this->sanitizer()->sanitizeUrl(
            'https://app.test/reset?token=abc123&email=a%40b.c&signature=xyz&page=2#top'
        );

        $this->assertSame('https://app.test/reset?token=***&email=a%40b.c&signature=***&page=2#top', $url);
        $this->assertSame('https://app.test/plain', $this->sanitizer()->sanitizeUrl('https://app.test/plain'));
    }

    public function test_string_query_bindings_are_redacted_by_default(): void
    {
        $bindings = $this->sanitizer()->sanitizeBindings(['a@b.c', 42, 1.5, null, true]);

        $this->assertSame(['***', 42, 1.5, null, true], $bindings);

        config(['debugmate.redact_query_bindings' => false]);

        $this->assertSame(['a@b.c'], $this->sanitizer()->sanitizeBindings(['a@b.c']));
    }

    public function test_stack_frame_string_arguments_are_not_sent(): void
    {
        if (filter_var(ini_get('zend.exception_ignore_args'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('PHP is configured not to record exception arguments.');
        }

        $parser = new StackTraceParser;

        $exception = (function (string $plainPassword) {
            return new \RuntimeException('boom');
        })('hunter2-plaintext');

        // Inspect arguments only: code snippets legitimately contain this file's source.
        $arguments = array_merge(...array_column($parser->toArray($parser->parseThrowable($exception)), 'arguments'));
        $values = array_column($arguments, 'value');

        $this->assertNotContains('hunter2-plaintext', $values);
        $this->assertContains('string(17)', $values);
    }
}
