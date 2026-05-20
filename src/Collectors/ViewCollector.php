<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;

class ViewCollector implements CollectorInterface
{
    protected array $spans = [];

    public function __construct(protected PerformanceMonitor $monitor)
    {
    }

    public function getName(): string
    {
        return 'views';
    }

    public function register(): void
    {
        try {
            \Event::listen('Illuminate\View\Events\CreatingView', function ($event) {
                try {
                    $span = $this->monitor->startSpan('view.render', [
                        'view.name' => $event->view ?? 'unknown',
                    ]);
                    $this->spans[$event->view] = $span;
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate ViewCollector: Error in CreatingView: ' . $e->getMessage());
                }
            });

            \Event::listen('Illuminate\View\Events\CreatedView', function ($event) {
                try {
                    $span = $this->spans[$event->view] ?? null;
                    if ($span) {
                        $span->setStatus('OK');
                        $this->monitor->endSpan($span);
                        unset($this->spans[$event->view]);
                    }
                } catch (\Throwable $e) {
                    \Log::debug('DebugMate ViewCollector: Error in CreatedView: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            \Log::debug('DebugMate ViewCollector: Error registering listeners: ' . $e->getMessage());
        }
    }
}


