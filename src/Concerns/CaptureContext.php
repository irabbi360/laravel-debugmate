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
        try {
            $user = auth()->user();
        } catch (\Throwable) {
            return [];
        }

        if (!$user) {
            return [];
        }

        $sendPii = (bool) config('debugmate.send_user_pii', false);

        return [
            'id' => $user->getAuthIdentifier(),
            'name' => $sendPii ? ($user->name ?? null) : null,
            'email' => $sendPii ? ($user->email ?? null) : null,
            'ip' => \Irabbi360\LaravelDebugMate\Support\Sanitizer::instance()->sanitizeIp(request()->ip()),
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
        if (!function_exists('session') || !session()->isStarted()) {
            return [];
        }

        // Never send the live session id (it could be used to hijack the session).
        return [
            'session_id_hash' => substr(hash('sha256', (string) session()->getId()), 0, 16),
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

