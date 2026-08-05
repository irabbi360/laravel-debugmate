<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Irabbi360\LaravelDebugMate\Console\VerifyCommand;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use Mockery;

class VerifyCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_verify_command_succeeds_when_api_connection_works(): void
    {
        $apiClient = Mockery::mock(ApiClient::class);
        $apiClient->shouldReceive('verifyConnection')->once()->andReturn(true);
        $apiClient->shouldReceive('getProjectInfo')->once()->andReturn([
            'name' => 'Demo App',
        ]);

        $this->app->instance(ApiClient::class, $apiClient);

        $this->artisan(VerifyCommand::class)
            ->expectsOutputToContain('Connection verified.')
            ->assertExitCode(0);
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
