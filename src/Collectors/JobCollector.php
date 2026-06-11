<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;

class JobCollector implements CollectorInterface
{
    protected array $spans = [];

    /** @var array<string, true> Jobs started in worker context (need their own flush) */
    protected array $workerJobs = [];

    public function __construct(protected PerformanceMonitor $monitor) {}

    public function getName(): string
    {
        return 'jobs';
    }

    public function register(): void
    {
        try {
            \Event::listen('Illuminate\Queue\Events\JobProcessing', function ($event) {
                try {
                    $jobName = $this->resolveJobName($event->job);

                    if ($jobName === null || $this->shouldSkipJob($jobName)) {
                        return;
                    }

                    $key = spl_object_hash($event->job);

                    // Worker context: no active HTTP trace — start + flush per job
                    if (! $this->monitor->hasActiveTrace()) {
                        $this->monitor->startJob(
                            $jobName,
                            $event->job->getQueue() ?? 'default',
                            $event->connectionName ?? 'default',
                        );
                        $this->workerJobs[$key] = true;
                    }

                    $span = $this->monitor->startSpan('queue.job', [
                        'job.class' => $jobName,
                        'job.name' => class_basename($jobName),
                        'job.queue' => $event->job->getQueue(),
                        'job.connection_name' => $event->connectionName ?? 'default',
                    ]);
                    $this->spans[$key] = $span;
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate JobCollector: Error in JobProcessing: '.$e->getMessage());
                }
            });

            \Event::listen('Illuminate\Queue\Events\JobProcessed', function ($event) {
                try {
                    $this->finishJob($event->job, 200);
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate JobCollector: Error in JobProcessed: '.$e->getMessage());
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
                    }

                    $this->finishJob($event->job, 500);
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate JobCollector: Error in JobFailed: '.$e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            \Log::debug('DebugMate JobCollector: Error registering listener: '.$e->getMessage());
        }
    }

    protected function finishJob(object $job, int $statusCode): void
    {
        $key = spl_object_hash($job);
        $span = $this->spans[$key] ?? null;

        if ($span) {
            if ($statusCode < 400) {
                $span->setStatus('OK');
            }
            $this->monitor->endSpan($span);
            unset($this->spans[$key]);
        }

        if (isset($this->workerJobs[$key])) {
            $this->monitor->flushJob($statusCode);
            unset($this->workerJobs[$key]);
        }
    }

    protected function resolveJobName(object $job): ?string
    {
        if (! method_exists($job, 'getName')) {
            return null;
        }

        $name = method_exists($job, 'resolveName')
            ? $job->resolveName()
            : $job->getName();

        if ($this->isInternalQueueHandler($name) && method_exists($job, 'resolveQueuedJobClass')) {
            $className = $job->resolveQueuedJobClass();

            if (! $this->isInternalQueueHandler($className)) {
                return $className;
            }
        }

        if ($this->isInternalQueueHandler($name)) {
            return null;
        }

        return $name;
    }

    protected function isInternalQueueHandler(string $jobName): bool
    {
        return $jobName === 'Illuminate\Queue\CallQueuedHandler@call'
            || str_starts_with($jobName, 'Illuminate\Queue\\');
    }

    protected function shouldSkipJob(string $jobName): bool
    {
        return $this->isInternalQueueHandler($jobName)
            || str_starts_with($jobName, 'Irabbi360\\LaravelDebugMate\\Jobs\\');
    }
}
