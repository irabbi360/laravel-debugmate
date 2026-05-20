<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;

class JobCollector implements CollectorInterface
{
    protected array $spans = [];

    public function __construct(protected PerformanceMonitor $monitor)
    {
    }

    public function getName(): string
    {
        return 'jobs';
    }

    public function register(): void
    {
        try {
            \Event::listen('Illuminate\Queue\Events\JobProcessing', function ($event) {
                try {
                    $key = spl_object_hash($event->job);
                    $span = $this->monitor->startSpan('queue.job', [
                        'job.name' => $event->job->getName(),
                        'job.queue' => $event->job->getQueue(),
                        'job.connection_name' => $event->connectionName ?? 'default',
                    ]);
                    $this->spans[$key] = $span;
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate JobCollector: Error in JobProcessing: ' . $e->getMessage());
                }
            });

            \Event::listen('Illuminate\Queue\Events\JobProcessed', function ($event) {
                try {
                    $key = spl_object_hash($event->job);
                    $span = $this->spans[$key] ?? null;
                    if ($span) {
                        $span->setStatus('OK');
                        $this->monitor->endSpan($span);
                        unset($this->spans[$key]);
                    }
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate JobCollector: Error in JobProcessed: ' . $e->getMessage());
                }
            });

            \Event::listen('Illuminate\Queue\Events\JobFailed', function ($event) {
                try {
                    $key = spl_object_hash($event->job);
                    $span = $this->spans[$key] ?? null;
                    if ($span) {
                        if (isset($event->exception) && $event->exception) {
                            $span->recordException($event->exception);
                        } else {
                            $span->setStatus('ERROR', 'Job failed');
                        }
                        $this->monitor->endSpan($span);
                        unset($this->spans[$key]);
                    }
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate JobCollector: Error in JobFailed: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            \Log::debug('DebugMate JobCollector: Error registering listener: ' . $e->getMessage());
        }
    }
}


