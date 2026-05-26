<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

/**
 * CaptureContext - Trait for capturing request/application context
 *
 * Collects user information, session data, environment variables, etc.
 */
trait CaptureContext
{
    /**
     * Capture full application context.
     */
    public function captureFullContext(): array
    {
        return [
            'request' => $this->captureRequestContext() ?? [],
            'user' => $this->captureUserContext(),
            'environment' => $this->captureEnvironmentContext(),
            'session' => $this->captureSessionContext(),
            'custom' => config('debugmate.context', []),
        ];
    }

    /**
     * Capture user context information.
     */
    public function captureUserContext(): array
    {
        $user = auth()->user();

        if (!$user) {
            return [];
        }

        return [
            'id' => $user->id ?? null,
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
            'ip' => request()->ip() ?? null,
        ];
    }

    /**
     * Capture environment context.
     */
    public function captureEnvironmentContext(): array
    {
        return [
            'app_name' => config('app.name'),
            'app_version' => config('app.version', '1.0.0'),
            'environment' => config('app.env'),
            'debug' => config('app.debug'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];
    }

    /**
     * Capture session context.
     */
    public function captureSessionContext(): array
    {
        if (!function_exists('session')) {
            return [];
        }

        return [
            'session_id' => session()->getId() ?? null,
            'session_name' => session()->getName() ?? null,
        ];
    }

    /**
     * Capture request context (depends on CaptureRequestData trait).
     */
    protected function captureRequestContext(): ?array
    {
        if (!method_exists($this, 'captureRequestData')) {
            return null;
        }

        return [
            'request_data' => $this->captureRequestData(),
        ];
    }
}

