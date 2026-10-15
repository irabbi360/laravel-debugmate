<?php

namespace Irabbi360\LaravelDebugMate\Collectors;

use Irabbi360\LaravelDebugMate\Concerns\CaptureSanitization;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Support\InternalLog;

class HttpClientCollector implements CollectorInterface
{
    use CaptureSanitization;

    /** Requests that never receive a response event must not pile up forever. */
    protected const MAX_PENDING_SPANS = 100;

    protected array $spans = [];

    public function __construct(protected PerformanceMonitor $monitor)
    {
    }

    public function getName(): string
    {
        return 'http_requests';
    }

    public function register(): void
    {
        // Track Illuminate\Http\Client (Laravel Http facade)
        if (!class_exists('\Illuminate\Http\Client\Events\RequestSending')) {
            return;
        }

        try {
            \Event::listen('Illuminate\Http\Client\Events\RequestSending', function ($event) {
                try {
                    if (! $this->monitor->hasActiveTrace()) {
                        return;
                    }

                    if (count($this->spans) >= self::MAX_PENDING_SPANS) {
                        $this->spans = [];
                    }

                    $key = $this->getRequestId($event);
                    $span = $this->monitor->startSpan('http.client.request', [
                        // Query strings often carry API keys / signatures.
                        'http.request.url' => (string) $this->sanitizeUrl((string) ($event->request->url() ?? 'unknown')),
                        'http.request.method' => $event->request->method() ?? 'UNKNOWN',
                    ]);
                    $this->spans[$key] = $span;
                } catch (\Throwable $e) {
                    InternalLog::debug('DebugMate HttpClientCollector: Error in RequestSending: ' . $e->getMessage());
                }
            });

            \Event::listen('Illuminate\Http\Client\Events\ResponseReceived', function ($event) {
                try {
                    $key = $this->getRequestId($event);
                    $span = $this->spans[$key] ?? null;
                    if ($span) {
                        $span->setAttribute('http.response.status_code', $event->response->status());
                        $span->setAttribute('http.response.size', strlen($event->response->body() ?? ''));

                        if ($event->response->successful()) {
                            $span->setStatus('OK');
                        } else {
                            $span->setStatus('ERROR', "HTTP {$event->response->status()}");
                        }

                        $this->monitor->endSpan($span);
                        unset($this->spans[$key]);
                    }
                } catch (\Throwable $e) {
                    InternalLog::debug('DebugMate HttpClientCollector: Error in ResponseReceived: ' . $e->getMessage());
                }
            });

            \Event::listen('Illuminate\Http\Client\Events\ConnectionFailed', function ($event) {
                try {
                    $key = $this->getRequestId($event);
                    $span = $this->spans[$key] ?? null;
                    if ($span) {
                        if (isset($event->exception) && $event->exception) {
                            $span->recordException($event->exception);
                        } else {
                            $span->setStatus('ERROR', 'Connection failed');
                        }
                        $this->monitor->endSpan($span);
                        unset($this->spans[$key]);
                    }
                } catch (\Throwable $e) {
                    InternalLog::debug('DebugMate HttpClientCollector: Error in ConnectionFailed: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            InternalLog::debug('DebugMate HttpClientCollector: Error registering listeners: ' . $e->getMessage());
        }
    }

    protected function getRequestId($event): string
    {
        // Use request hash if available, otherwise use event hash
        if (isset($event->request)) {
            return spl_object_hash($event->request);
        }
        return spl_object_hash($event);
    }
}


