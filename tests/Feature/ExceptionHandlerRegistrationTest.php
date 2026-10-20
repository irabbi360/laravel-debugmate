<?php

namespace Irabbi360\LaravelDebugMate\Tests\Feature;

use Illuminate\Foundation\Exceptions\Handler;
use Irabbi360\LaravelDebugMate\Services\ErrorTracker;
use Irabbi360\LaravelDebugMate\Services\ExceptionHandler;
use Irabbi360\LaravelDebugMate\Tests\TestCase;
use Mockery;
use RuntimeException;

class ExceptionHandlerRegistrationTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_register_on_reports_through_a_laravel_10_style_handler(): void
    {
        $exception = new RuntimeException('boom');

        $tracker = Mockery::mock(ErrorTracker::class);
        $tracker->shouldReceive('reportError')->once()->with($exception);
        $this->app->instance(ErrorTracker::class, $tracker);

        $handler = new Handler($this->app);
        ExceptionHandler::registerOn($handler);

        $handler->report($exception);
    }

    public function test_register_on_does_nothing_when_disabled(): void
    {
        config(['debugmate.track_errors' => false]);

        $tracker = Mockery::mock(ErrorTracker::class);
        $tracker->shouldNotReceive('reportError');
        $this->app->instance(ErrorTracker::class, $tracker);

        $handler = new Handler($this->app);
        ExceptionHandler::registerOn($handler);

        $handler->report(new RuntimeException('ignored'));
    }

    public function test_a_failing_reporter_never_breaks_the_handler(): void
    {
        $tracker = Mockery::mock(ErrorTracker::class);
        $tracker->shouldReceive('reportError')->once()->andThrow(new RuntimeException('reporting failed'));
        $this->app->instance(ErrorTracker::class, $tracker);

        $handler = new Handler($this->app);
        ExceptionHandler::registerOn($handler);

        $handler->report(new RuntimeException('original'));
    }
}
