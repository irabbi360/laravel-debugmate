<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;

/**
 * LivewireCollector - Tracks Livewire component performance.
 *
 * This collector:
 * - Tracks component lifecycle (mount, hydrate, update, render)
 * - Records component method calls
 * - Monitors property updates
 * - Captures render times and re-render counts
 * - Detects performance issues in real-time interactions
 */
class LivewireCollector implements CollectorInterface
{
    protected array $componentSpans = [];
    protected array $methodSpans = [];

    public function __construct(protected PerformanceMonitor $monitor)
    {
    }

    public function getName(): string
    {
        return 'livewire';
    }

    public function register(): void
    {
        // Check if Livewire is installed
        if (!class_exists('\Livewire\Livewire')) {
            return;
        }

        try {
            $this->registerComponentLifecycle();
            $this->registerMethodCalls();
            $this->registerPropertyUpdates();
        } catch (\Throwable $e) {
            \Log::debug('DebugMate LivewireCollector: Error registering listeners: ' . $e->getMessage());
        }
    }

    /**
     * Register component lifecycle tracking.
     */
    protected function registerComponentLifecycle(): void
    {
        try {
            // Hook into component initialization
            if (class_exists('\Livewire\Lifecycle\Mount')) {
                \Event::listen('livewire:mount', function ($component) {
                    try {
                        $componentId = spl_object_hash($component);
                        $span = $this->monitor->startSpan('livewire.component.mount', [
                            'livewire.component_name' => class_basename($component),
                            'livewire.component_class' => get_class($component),
                        ]);
                        $this->componentSpans[$componentId] = [
                            'span' => $span,
                            'component_name' => class_basename($component),
                            'mounted_at' => microtime(true),
                        ];
                    } catch (\Throwable $e) {
                        \Log::debug('DebugMate LivewireCollector: Error in mount: ' . $e->getMessage());
                    }
                });
            }

            // Hook into component rendering
            if (class_exists('\Livewire\Lifecycle\Render')) {
                \Event::listen('livewire:rendering', function ($component) {
                    try {
                        $componentId = spl_object_hash($component);
                        $span = $this->monitor->startSpan('livewire.component.render', [
                            'livewire.component_name' => class_basename($component),
                            'livewire.request_id' => request()?->id() ?? 'unknown',
                        ]);
                        $this->componentSpans[$componentId]['render_span'] = $span;
                    } catch (\Throwable $e) {
                        \Log::debug('DebugMate LivewireCollector: Error in render: ' . $e->getMessage());
                    }
                });
            }

            // Hook into component update
            if (class_exists('\Livewire\Lifecycle\Update')) {
                \Event::listen('livewire:updating', function ($component, $name, $value) {
                    try {
                        $componentId = spl_object_hash($component);
                        $span = $this->monitor->startSpan('livewire.component.update', [
                            'livewire.component_name' => class_basename($component),
                            'livewire.property_name' => $name,
                            'livewire.old_value' => is_scalar($value) ? (string)$value : gettype($value),
                        ]);
                        $this->methodSpans[$componentId . ':' . $name] = $span;
                    } catch (\Throwable $e) {
                        \Log::debug('DebugMate LivewireCollector: Error in update: ' . $e->getMessage());
                    }
                });

                \Event::listen('livewire:updated', function ($component, $name, $value) {
                    try {
                        $key = spl_object_hash($component) . ':' . $name;
                        if (isset($this->methodSpans[$key])) {
                            $span = $this->methodSpans[$key];
                            $span->setAttribute('livewire.new_value', is_scalar($value) ? (string)$value : gettype($value));
                            $span->setStatus('OK');
                            $this->monitor->endSpan($span);
                            unset($this->methodSpans[$key]);
                        }
                    } catch (\Throwable $e) {
                        \Log::debug('DebugMate LivewireCollector: Error in updated: ' . $e->getMessage());
                    }
                });
            }

            // Hook into component destroy
            if (class_exists('\Livewire\Component')) {
                \Event::listen('livewire:destroyed', function ($component) {
                    try {
                        $componentId = spl_object_hash($component);
                        if (isset($this->componentSpans[$componentId])) {
                            $data = $this->componentSpans[$componentId];
                            if (isset($data['render_span'])) {
                                $this->monitor->endSpan($data['render_span']);
                            }
                            if (isset($data['span'])) {
                                $this->monitor->endSpan($data['span']);
                            }
                            unset($this->componentSpans[$componentId]);
                        }
                    } catch (\Throwable $e) {
                        \Log::debug('DebugMate LivewireCollector: Error in destroyed: ' . $e->getMessage());
                    }
                });
            }
        } catch (\Throwable $e) {
            \Log::debug('DebugMate LivewireCollector: Error registering lifecycle: ' . $e->getMessage());
        }
    }

    /**
     * Register method call tracking.
     */
    protected function registerMethodCalls(): void
    {
        try {
            // This would need custom integration with Livewire's method dispatching
            // For now, we rely on event listeners for lifecycle tracking
            if (method_exists('\Livewire\Livewire', 'hook')) {
                // Livewire v3 hook system
                \Livewire\Livewire::hook('component.call', function ($component, $method, $params) {
                    try {
                        $componentId = spl_object_hash($component);
                        $span = $this->monitor->startSpan('livewire.method_call', [
                            'livewire.component_name' => class_basename($component),
                            'livewire.method_name' => $method,
                            'livewire.params_count' => count($params ?? []),
                        ]);
                        $this->methodSpans[$componentId . ':' . $method] = $span;
                    } catch (\Throwable $e) {
                        \Log::debug('DebugMate LivewireCollector: Error in method hook: ' . $e->getMessage());
                    }
                });

                \Livewire\Livewire::hook('component.call.end', function ($component, $method, $params) {
                    try {
                        $key = spl_object_hash($component) . ':' . $method;
                        if (isset($this->methodSpans[$key])) {
                            $span = $this->methodSpans[$key];
                            $span->setStatus('OK');
                            $this->monitor->endSpan($span);
                            unset($this->methodSpans[$key]);
                        }
                    } catch (\Throwable $e) {
                        \Log::debug('DebugMate LivewireCollector: Error in method end hook: ' . $e->getMessage());
                    }
                });
            }
        } catch (\Throwable $e) {
            \Log::debug('DebugMate LivewireCollector: Error registering method calls: ' . $e->getMessage());
        }
    }

    /**
     * Register property update tracking (already handled in lifecycle).
     */
    protected function registerPropertyUpdates(): void
    {
        // Property updates are tracked in the Update/Updated lifecycle hooks
        // This method is a placeholder for future enhancements
    }

    /**
     * Get component performance data.
     */
    public function getComponentData(): array
    {
        return array_map(function ($data) {
            return [
                'component_name' => $data['component_name'],
                'mounted_at' => $data['mounted_at'],
                'duration_ms' => ($data['span']?->getDurationMs() ?? 0),
            ];
        }, $this->componentSpans);
    }
}

