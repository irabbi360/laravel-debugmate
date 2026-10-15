<?php

namespace Irabbi360\LaravelDebugMate\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
use Irabbi360\LaravelDebugMate\Support\InternalLog;
use Symfony\Component\HttpFoundation\Response;

class TrackPerformance
{
    public function __construct(protected PerformanceMonitor $monitor)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Skip if performance tracking is disabled
        if (!config('debugmate.track_performance')) {
            return $next($request);
        }

        // Skip ignored paths
        foreach (config('debugmate.ignore_paths', []) as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        // Apply sample rate — skip randomly based on configured rate
        $sampleRate = (float) config('debugmate.sample_rate', 1.0);
        if ($sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) > $sampleRate) {
            return $next($request);
        }

        // Start collecting — with distributed tracing support
        // Check for incoming traceparent header (from upstream service)
        $this->monitor->startRequest($request->header('traceparent'));
        $request->attributes->set('debugmate_traced', true);

        $response = $next($request);

        // Stop the clock now; the payload is sent in terminate(), after the
        // response has gone to the client, so reporting never adds latency.
        $this->monitor->finishRequest();

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->attributes->get('debugmate_traced') || ! $this->monitor->hasActiveTrace()) {
            return;
        }

        try {
            $this->monitor->flushRequest(
                // Secrets in the path (/reset-password/{token}) are masked.
                endpoint: '/' . ltrim((string) parse_url($this->monitor->sanitizeRequestUrl($request, false), PHP_URL_PATH), '/'),
                method: $request->method(),
                statusCode: $response->getStatusCode(),
            );
        } catch (\Throwable $e) {
            InternalLog::error('DebugMate: flushRequest failed', ['error' => $e->getMessage()]);
        }
    }
}
