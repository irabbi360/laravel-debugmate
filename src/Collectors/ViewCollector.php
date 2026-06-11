<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\View\Factory;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Tracing\Span;
use Irabbi360\LaravelDebugMate\View\TracingViewEngine;

class ViewCollector implements CollectorInterface
{
    /** @var array<int, Span> */
    protected array $spanStack = [];

    public function __construct(protected PerformanceMonitor $monitor) {}

    public function getName(): string
    {
        return 'views';
    }

    public function register(): void
    {
        try {
            $this->app()->booted(function () {
                $this->registerViewComposer();
                $this->wrapViewEngines();
            });
        } catch (\Throwable $e) {
            \Log::debug('DebugMate ViewCollector: Error registering listeners: '.$e->getMessage());
        }
    }

    public function endCurrentViewSpan(): void
    {
        if ($this->spanStack === []) {
            return;
        }

        $span = array_pop($this->spanStack);
        $span->setStatus(Span::STATUS_OK);
        $this->monitor->endSpan($span);
    }

    protected function registerViewComposer(): void
    {
        $factory = $this->viewFactory();

        $factory->composer('*', function (ViewContract $view) {
            if (! $this->monitor->hasActiveTrace()) {
                return;
            }

            $this->spanStack[] = $this->monitor->startSpan('view.render', [
                'view.name' => $this->resolveViewName($view),
            ]);
        });
    }

    protected function wrapViewEngines(): void
    {
        $resolver = $this->viewFactory()->getEngineResolver();

        foreach (['blade', 'php', 'file'] as $engineName) {
            try {
                $originalEngine = $resolver->resolve($engineName);
            } catch (\Throwable) {
                continue;
            }

            $resolver->forget($engineName);

            $collector = $this;

            $resolver->register($engineName, function () use ($originalEngine, $collector) {
                return new TracingViewEngine($originalEngine, $collector);
            });
        }
    }

    protected function resolveViewName(mixed $view): string
    {
        if (is_object($view) && method_exists($view, 'getName')) {
            return $view->getName();
        }

        if (is_object($view) && method_exists($view, 'name')) {
            return $view->name();
        }

        return is_string($view) ? $view : 'unknown';
    }

    protected function viewFactory(): Factory
    {
        return $this->app()['view'];
    }

    protected function app(): Application
    {
        return app();
    }
}
