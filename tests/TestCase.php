<?php

namespace Irabbi360\LaravelDebugMate\Tests;

use Irabbi360\LaravelDebugMate\DebugMateServiceProvider;
use Irabbi360\LaravelDebugMate\Services\ApiClient;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        ApiClient::resetCircuit();
    }

    protected function getPackageProviders($app): array
    {
        return [
            DebugMateServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('debugmate.enabled', true);
        $app['config']->set('debugmate.debugmate_key', 'dm_test_key');
        $app['config']->set('debugmate.track_errors', true);
        $app['config']->set('debugmate.track_performance', false);
        $app['config']->set('debugmate.track_queries', true);
        $app['config']->set('debugmate.async_reporting', false);
        $app['config']->set('debugmate.sample_rate', 1.0);
    }
}
