<?php

namespace Irabbi360\LaravelDebugMate\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Irabbi360\LaravelDebugMate\Services\PerformanceMonitor;
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
        $traceParentHeader = $request->header('traceparent');
        $this->monitor->startRequest($traceParentHeader);

        $response = $next($request);

        // Flush ONE batched payload after the response is built
        try {
            $this->monitor->flushRequest(
                endpoint: '/' . ltrim($request->path(), '/'),
                method: $request->method(),
                statusCode: $response->getStatusCode(),
            );
        } catch (\Throwable $e) {
            Log::error('DebugMate: flushRequest failed', ['error' => $e->getMessage()]);
        }

        return $response;
    }
}
