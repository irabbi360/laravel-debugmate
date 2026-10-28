<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Irabbi360\LaravelDebugMate\Console\VerifyCommand;
use Irabbi360\LaravelDebugMate\Exceptions\DebugMateTestException;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Services\ErrorTracker;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use Mockery;

class VerifyCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Bind a mocked ApiClient that passes verification, and rebuild the ErrorTracker around it.
     */
    private function connectedApiClient(): ApiClient|Mockery\MockInterface
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('verifyConnection')->once()->andReturn(true);
        $apiClient->shouldReceive('getProjectInfo')->once()->andReturn([
            'status' => 'success',
            'data' => ['title' => 'Demo App', 'slug' => 'demo-app'],
        ]);

        $this->app->instance(ApiClient::class, $apiClient);
        $this->app->forgetInstance(ErrorTracker::class);

        return $apiClient;
    }

    public function test_verify_command_succeeds_and_sends_a_test_error(): void
    {
        config(['debugmate.api_url' => 'https://debugmate.test']);
        $this->connectedApiClient()->shouldReceive('reportError')->once()->andReturn(true);

        $this->artisan(VerifyCommand::class)
            ->expectsOutputToContain('Connection verified.')
            ->expectsOutputToContain('Demo App')
            ->expectsOutputToContain('Test error sent')
            ->expectsOutputToContain('https://debugmate.test/projects/demo-app/errors')
            ->assertExitCode(0);
    }

    public function test_the_test_error_is_a_labelled_exception_sent_directly(): void
    {
        config(['debugmate.async_reporting' => true, 'debugmate.sample_rate' => 0.0]);
        $sent = null;

        $this->connectedApiClient()->shouldReceive('reportError')->once()->andReturnUsing(function (array $payload) use (&$sent) {
            $sent = $payload;

            return true;
        });

        $this->artisan(VerifyCommand::class)->assertExitCode(0);

        $this->assertSame(class_basename(DebugMateTestException::class), $sent['error_type']);
        $this->assertStringContainsString('debugmate:verify', $sent['message']);
        $this->assertNotEmpty($sent['frames']);
    }

    public function test_skip_test_error_only_checks_the_connection(): void
    {
        $this->connectedApiClient()->shouldNotReceive('reportError');

        $this->artisan(VerifyCommand::class, ['--skip-test-error' => true])
            ->expectsOutputToContain('Connection verified.')
            ->doesntExpectOutputToContain('Test error sent')
            ->assertExitCode(0);
    }

    public function test_verify_command_fails_when_the_test_error_is_rejected(): void
    {
        $this->connectedApiClient()->shouldReceive('reportError')->once()->andReturn(false);

        $this->artisan(VerifyCommand::class)
            ->expectsOutputToContain('test error was not accepted')
            ->assertExitCode(1);
    }

    public function test_verify_command_fails_when_debugmate_disabled(): void
    {
        config(['debugmate.enabled' => false]);

        $this->artisan(VerifyCommand::class)
            ->expectsOutputToContain('DebugMate is disabled')
            ->assertExitCode(1);
    }

    public function test_verify_command_fails_when_project_key_missing(): void
    {
        config(['debugmate.debugmate_key' => null]);

        $this->artisan(VerifyCommand::class)
            ->expectsOutputToContain('DEBUGMATE_PROJECT_KEY is not configured')
            ->assertExitCode(1);
    }

    public function test_verify_command_fails_when_api_unreachable(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('verifyConnection')->once()->andReturn(false);

        $this->app->instance(ApiClient::class, $apiClient);

        $this->artisan(VerifyCommand::class)
            ->expectsOutputToContain('Connection failed')
            ->assertExitCode(1);
    }
}
