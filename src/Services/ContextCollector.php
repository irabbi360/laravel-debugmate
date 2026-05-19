<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContextCollector
{
    /**
     * Collect all contextual information about the error
     */
    public function collect(Request $request): array
    {
        // Collect all information
        $collected = [
            'app' => $this->collectAppInfo($request),
            'request' => $this->collectRequestInfo($request),
            'context' => $this->collectContextInfo($request),
        ];

        // Add captured queries at the end - get all queries that have been logged
        $collected['queries'] = $this->collectQueryInfo();

        return $collected;
    }

    /**
     * Collect APP information (Routing, Browser)
     */
    protected function collectAppInfo(Request $request): array
    {
        return [
            'routing' => $this->getRoutingInfo($request),
            'browser' => $this->getBrowserInfo($request),
        ];
    }

    /**
     * Get routing information
     */
    protected function getRoutingInfo(Request $request): array
    {
        $route = $request->route();

        return [
            'method' => $request->method(),
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            'route_name' => $route?->getName() ?? null,
            'route_action' => $route?->getActionName() ?? null,
            'controller' => $route?->getControllerClass() ?? null,
            'middleware' => $route?->middleware() ?? [],
            'parameters' => $route?->parameters() ?? [],
        ];
    }

    /**
     * Get browser/user agent information
     */
    protected function getBrowserInfo(Request $request): array
    {
        $userAgent = $request->userAgent() ?? '';

        return [
            'user_agent' => $userAgent,
            'ip_address' => $request->ip(),
            'is_ajax' => $request->ajax(),
            'is_json' => $request->isJson(),
            'is_secure' => $request->secure(),
            'device' => $this->detectDevice($userAgent),
            'browser' => $this->detectBrowser($userAgent),
        ];
    }

    /**
     * Collect REQUEST information (Headers, Session, Cookies)
     */
    protected function collectRequestInfo(Request $request): array
    {
        return [
            'headers' => $this->getHeadersInfo($request),
            'session' => $this->getSessionInfo($request),
            'cookies' => $this->getCookiesInfo($request),
            'body' => $this->getBodyInfo($request),
        ];
    }

    /**
     * Get headers information (sanitized)
     */
    protected function getHeadersInfo(Request $request): array
    {
        $headers = $request->headers->all();
        $sensitive = ['authorization', 'cookie', 'x-api-key', 'x-access-token', 'authentication'];

        $sanitized = [];
        foreach ($headers as $key => $value) {
            if (!in_array(strtolower($key), $sensitive)) {
                $sanitized[$key] = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get session information
     */
    protected function getSessionInfo(Request $request): array
    {
        if (!session()->isStarted()) {
            return [];
        }

        $session = session()->all();
        $sensitive = ['password', 'token', 'secret', 'api_key', 'credit_card'];

        $sanitized = [];
        foreach ($session as $key => $value) {
            if (!$this->isSensitive($key, $sensitive)) {
                $sanitized[$key] = $this->formatValue($value);
            }
        }

        return [
            'id' => session()->getId(),
            'data' => $sanitized,
            'lifetime' => config('session.lifetime'),
        ];
    }

    /**
     * Get cookies information (sanitized)
     */
    protected function getCookiesInfo(Request $request): array
    {
        $cookies = $request->cookies->all();
        $sensitive = ['session', 'token', 'auth', 'api_key'];

        $sanitized = [];
        foreach ($cookies as $key => $value) {
            if (!$this->isSensitive($key, $sensitive)) {
                $sanitized[$key] = is_string($value) ? substr($value, 0, 50) : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get request body information (sanitized)
     */
    protected function getBodyInfo(Request $request): array
    {
        $input = $request->all();
        $sensitive = ['password', 'token', 'secret', 'credit_card', 'ssn', 'api_key'];

        $sanitized = [];
        foreach ($input as $key => $value) {
            if (!$this->isSensitive($key, $sensitive)) {
                $sanitized[$key] = $this->formatValue($value);
            }
        }

        return $sanitized;
    }

    /**
     * Collect CONTEXT information (User, Git, Laravel, Application, Resource)
     */
    protected function collectContextInfo(Request $request): array
    {
        return [
            'user' => $this->getUserInfo($request),
            'git' => $this->getGitInfo(),
            'laravel' => $this->getLaravelInfo(),
            'application' => $this->getApplicationInfo(),
            'resource' => $this->getResourceInfo(),
        ];
    }

    /**
     * Get authenticated user information
     */
    protected function getUserInfo(Request $request): array
    {
        $user = auth()->user();

        if (!$user) {
            return [
                'authenticated' => false,
                'guard' => auth()->getDefaultDriver(),
            ];
        }

        return [
            'authenticated' => true,
            'id' => $user->getKey(),
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
            'guard' => auth()->getDefaultDriver(),
            'roles' => method_exists($user, 'getRoles') ? $user->getRoles() : [],
            'permissions' => method_exists($user, 'getPermissions') ? $user->getPermissions() : [],
        ];
    }

    /**
     * Get Git information
     */
    protected function getGitInfo(): array
    {
        $gitDir = base_path('.git');

        if (!is_dir($gitDir)) {
            return [
                'available' => false,
            ];
        }

        try {
            $head = trim(file_get_contents(base_path('.git/HEAD')));
            $branch = str_replace('ref: refs/heads/', '', $head);

            $commitFile = base_path('.git/refs/heads/' . $branch);
            $commit = trim(file_get_contents($commitFile));

            return [
                'available' => true,
                'branch' => $branch,
                'commit' => substr($commit, 0, 7),
                'commit_full' => $commit,
            ];
        } catch (\Exception $e) {
            return [
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get Laravel application information
     */
    protected function getLaravelInfo(): array
    {
        return [
            'version' => app()->version(),
            'environment' => app()->environment(),
            'debug' => config('app.debug'),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'url' => config('app.url'),
        ];
    }

    /**
     * Get application information
     */
    protected function getApplicationInfo(): array
    {
        return [
            'name' => config('app.name'),
            'environment' => app()->environment(),
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
            'php_version' => phpversion(),
            'database' => config('database.default'),
        ];
    }

    /**
     * Get resource information
     */
    protected function getResourceInfo(): array
    {
        return [
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];
    }

    /**
     * Collect executed database queries using Laravel's query log
     */
    protected function collectQueryInfo(): array
    {
        $queryLog = DB::getQueryLog();

        $processed = [];
        foreach ($queryLog as $query) {
            $processed[] = [
                'query' => $query['query'],
                'bindings' => $query['bindings'] ?? [],
                'time' => $query['time'] ?? 0,
            ];
        }

        return [
            'executed' => count($processed),
            'queries' => $processed,
        ];
    }

    /**
     * Detect device type from user agent
     */
    protected function detectDevice(string $userAgent): string
    {
        if (preg_match('/mobile|android|iphone|ipad|phone/i', $userAgent)) {
            return 'Mobile';
        }
        if (preg_match('/tablet|ipad/i', $userAgent)) {
            return 'Tablet';
        }
        return 'Desktop';
    }

    /**
     * Detect browser from user agent
     */
    protected function detectBrowser(string $userAgent): string
    {
        if (preg_match('/chrome/i', $userAgent)) {
            return 'Chrome';
        }
        if (preg_match('/firefox/i', $userAgent)) {
            return 'Firefox';
        }
        if (preg_match('/safari/i', $userAgent)) {
            return 'Safari';
        }
        if (preg_match('/edge/i', $userAgent)) {
            return 'Edge';
        }
        return 'Unknown';
    }

    /**
     * Check if key contains sensitive information
     */
    protected function isSensitive(string $key, array $sensitiveKeys): bool
    {
        $key = strtolower($key);
        foreach ($sensitiveKeys as $sensitive) {
            if (strpos($key, strtolower($sensitive)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Format value for display
     */
    protected function formatValue(mixed $value): mixed
    {
        if (is_object($value)) {
            return get_class($value);
        }
        if (is_array($value)) {
            return 'array (' . count($value) . ')';
        }
        if (is_string($value) && strlen($value) > 100) {
            return substr($value, 0, 100) . '...';
        }
        return $value;
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

