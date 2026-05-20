<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;

class CommandCollector implements CollectorInterface
{
    protected array $spans = [];

    public function __construct(protected PerformanceMonitor $monitor)
    {
    }

    public function getName(): string
    {
        return 'commands';
    }

    public function register(): void
    {
        try {
            \Event::listen('Illuminate\Console\Events\CommandStarting', function ($event) {
                try {
                    $span = $this->monitor->startSpan('console.command', [
                        'command.name' => $event->command ?? 'unknown',
                    ]);
                    $this->spans[$event->command] = $span;
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate CommandCollector: Error in CommandStarting: ' . $e->getMessage());
                }
            });

            \Event::listen('Illuminate\Console\Events\CommandFinished', function ($event) {
                try {
                    $span = $this->spans[$event->command] ?? null;
                    if ($span) {
                        $span->setAttribute('command.exit_code', $event->exitCode ?? 0);
                        if (($event->exitCode ?? 0) === 0) {
                            $span->setStatus('OK');
                        } else {
                            $span->setStatus('ERROR', "Command exited with code {$event->exitCode}");
                        }
                        $this->monitor->endSpan($span);
                        unset($this->spans[$event->command]);
                    }
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate CommandCollector: Error in CommandFinished: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            \Log::debug('DebugMate CommandCollector: Error registering listeners: ' . $e->getMessage());
        }
    }
}


