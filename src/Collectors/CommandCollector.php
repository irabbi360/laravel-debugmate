<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Illuminate\Support\Str;
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

    /**
     * Commands that must never open a trace.
     *
     * Long-running daemons (queue:work, horizon, octane:start, ...) fire
     * CommandStarting but only fire CommandFinished when the process exits,
     * which can be days later. Tracing one keeps a root span open for the
     * entire process lifetime, so:
     *
     *   - every span and query accumulates in memory, unbounded;
     *   - hasActiveTrace() stays true, so JobCollector never flushes per job
     *     and instead folds every job into the one daemon trace;
     *   - the eventual flush emits a single enormous payload.
     *
     * This is the primary source of worker memory growth, so the daemon list
     * is enforced here even if a user empties `debugmate.ignore_commands`.
     */
    protected const ALWAYS_SKIPPED_COMMANDS = [
        'queue:work',
        'queue:listen',
        'horizon',
        'horizon:work',
        'horizon:supervisor',
        'schedule:work',
        'octane:start',
        'reverb:start',
        'websockets:serve',
        'pail',
        'serve',
    ];

    protected function shouldSkipCommand(string $commandName): bool
    {
        $patterns = array_merge(
            self::ALWAYS_SKIPPED_COMMANDS,
            (array) config('debugmate.ignore_commands', []),
        );

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $commandName)) {
                return true;
            }
        }

        return false;
    }
}
