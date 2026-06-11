<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;

class CommandCollector implements CollectorInterface
{
    protected array $spans = [];

    /** @var array<string, true> Commands started in CLI context (need their own flush) */
    protected array $cliCommands = [];

    public function __construct(protected PerformanceMonitor $monitor) {}

    public function getName(): string
    {
        return 'commands';
    }

    public function register(): void
    {
        try {
            \Event::listen('Illuminate\Console\Events\CommandStarting', function ($event) {
                try {
                    $commandName = $event->command ?? 'unknown';

                    if ($this->shouldSkipCommand($commandName)) {
                        return;
                    }

                    $key = $commandName;

                    if (! $this->monitor->hasActiveTrace()) {
                        $this->monitor->startCommand($commandName);
                        $this->cliCommands[$key] = true;
                    }

                    $span = $this->monitor->startSpan('console.command', [
                        'command.name' => $commandName,
                    ]);
                    $this->spans[$key] = $span;
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate CommandCollector: Error in CommandStarting: '.$e->getMessage());
                }
            });

            \Event::listen('Illuminate\Console\Events\CommandFinished', function ($event) {
                try {
                    $this->finishCommand($event->command ?? 'unknown', $event->exitCode ?? 0);
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate CommandCollector: Error in CommandFinished: '.$e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            \Log::debug('DebugMate CommandCollector: Error registering listeners: '.$e->getMessage());
        }
    }

    protected function finishCommand(string $commandName, int $exitCode): void
    {
        $span = $this->spans[$commandName] ?? null;

        if ($span) {
            $span->setAttribute('command.exit_code', $exitCode);
            if ($exitCode === 0) {
                $span->setStatus('OK');
            } else {
                $span->setStatus('ERROR', "Command exited with code {$exitCode}");
            }
            $this->monitor->endSpan($span);
            unset($this->spans[$commandName]);
        }

        if (isset($this->cliCommands[$commandName])) {
            $this->monitor->flushCommand($exitCode === 0 ? 200 : 500);
            unset($this->cliCommands[$commandName]);
        }
    }

    protected function shouldSkipCommand(string $commandName): bool
    {
        return in_array($commandName, ['list', 'help', 'clear-compiled'], true);
    }
}
