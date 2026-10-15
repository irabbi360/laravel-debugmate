<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Irabbi360\LaravelDebugMate\Concerns\CaptureSanitization;

class ContextCollector
{
    use CaptureSanitization;

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
            'full_url' => $this->sanitizeRequestUrl($request),
            'route_name' => $route?->getName() ?? null,
            'route_action' => $route?->getActionName() ?? null,
            'controller' => $route?->getControllerClass() ?? null,
            'middleware' => $route?->middleware() ?? [],
            'parameters' => $this->sanitizeData(
                array_map(fn ($value) => $this->formatValue($value), $route?->parameters() ?? [])
            ),
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
            'ip_address' => $this->sanitizeIp($request->ip()),
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
        $headers = [];
        foreach ($request->headers->all() as $key => $value) {
            $headers[$key] = is_array($value) ? implode(', ', $value) : $value;
        }

        // Referer may carry tokens in its query string.
        if (isset($headers['referer'])) {
            $headers['referer'] = $this->sanitizeUrl($headers['referer']);
        }

        return $this->sanitizeData($this->sanitizeIpHeaders($headers));
    }

    /**
     * Get session information
     */
    protected function getSessionInfo(Request $request): array
    {
        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return [];
        }

        $session = $request->session();

        $data = [];
        foreach ($session->all() as $key => $value) {
            $data[$key] = $this->formatValue($value);
        }

        return [
            // Never send the live session id - anyone who can read the report
            // could hijack the session. A one-way hash still allows correlation.
            'id_hash' => substr(hash('sha256', (string) $session->getId()), 0, 16),
            'data' => $this->sanitizeData($data),
            'lifetime' => config('session.lifetime'),
        ];
    }

    /**
     * Get cookies information (sanitized)
     */
    protected function getCookiesInfo(Request $request): array
    {
        // Cookie values are session / remember-me credentials in most apps.
        // Only report which cookies were present.
        return array_fill_keys(array_keys($request->cookies->all()), '***');
    }

    /**
     * Get request body information (sanitized)
     */
    protected function getBodyInfo(Request $request): array
    {
        $sanitized = [];
        foreach ($this->sanitizeData($request->all()) as $key => $value) {
            $sanitized[$key] = $value === '***SANITIZED***' ? $value : $this->formatValue($value);
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
        try {
            $user = auth()->user();
        } catch (\Throwable) {
            // e.g. the database is down - which may be the error being reported
            return ['authenticated' => null];
        }

        if (! $user) {
            return [
                'authenticated' => false,
                'guard' => auth()->getDefaultDriver(),
            ];
        }

        $info = [
            'authenticated' => true,
            'id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
            'guard' => auth()->getDefaultDriver(),
        ];

        if ((bool) config('debugmate.send_user_pii', false)) {
            $info['name'] = $user->name ?? null;
            $info['email'] = $user->email ?? null;
        }

        try {
            $info['roles'] = method_exists($user, 'getRoles') ? $user->getRoles() : [];
            $info['permissions'] = method_exists($user, 'getPermissions') ? $user->getPermissions() : [];
        } catch (\Throwable) {
            // optional data - ignore
        }

        return $info;
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
            $head = trim((string) @file_get_contents($gitDir.'/HEAD'));

            if ($head === '') {
                return ['available' => false];
            }

            // Detached HEAD: the file holds the commit hash itself.
            if (! str_starts_with($head, 'ref: ')) {
                return [
                    'available' => true,
                    'branch' => null,
                    'commit' => substr($head, 0, 7),
                    'commit_full' => $head,
                ];
            }

            $ref = substr($head, 5);
            $branch = str_replace('refs/heads/', '', $ref);
            $commit = $this->resolveGitRef($gitDir, $ref);

            return [
                'available' => true,
                'branch' => $branch,
                'commit' => $commit ? substr($commit, 0, 7) : null,
                'commit_full' => $commit,
            ];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    /**
     * Resolve a ref from a loose ref file or, after `git gc`, from packed-refs.
     */
    protected function resolveGitRef(string $gitDir, string $ref): ?string
    {
        // Reject anything that could escape the .git directory.
        if (str_contains($ref, '..')) {
            return null;
        }

        $looseRef = $gitDir.'/'.$ref;
        if (is_file($looseRef)) {
            $commit = trim((string) @file_get_contents($looseRef));

            return $commit !== '' ? $commit : null;
        }

        $packedRefs = $gitDir.'/packed-refs';
        if (is_file($packedRefs)) {
            foreach (file($packedRefs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_ends_with($line, ' '.$ref)) {
                    return strtok($line, ' ') ?: null;
                }
            }
        }

        return null;
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
        try {
            $queryLog = DB::getQueryLog();
        } catch (\Throwable) {
            $queryLog = [];
        }

        // Keep the most recent queries only; the log can be huge in long requests.
        $queryLog = array_slice($queryLog, -100);

        $processed = [];
        foreach ($queryLog as $query) {
            $processed[] = [
                'query' => $this->sanitizeQuery((string) $query['query']),
                'bindings' => $this->sanitizeBindings($query['bindings'] ?? []),
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
        if (preg_match('/tablet|ipad|playbook|silk/i', $userAgent)
            || (preg_match('/android/i', $userAgent) && ! preg_match('/mobile/i', $userAgent))) {
            return 'Tablet';
        }
        if (preg_match('/mobile|iphone|ipod|android|phone/i', $userAgent)) {
            return 'Mobile';
        }
        return 'Desktop';
    }

    /**
     * Detect browser from user agent
     */
    protected function detectBrowser(string $userAgent): string
    {
        // Order matters: Edge and Opera also send "Chrome", Chrome also sends "Safari".
        if (preg_match('/edg(e|a|ios)?\//i', $userAgent)) {
            return 'Edge';
        }
        if (preg_match('/opr\/|opera/i', $userAgent)) {
            return 'Opera';
        }
        if (preg_match('/chrome|crios/i', $userAgent)) {
            return 'Chrome';
        }
        if (preg_match('/firefox|fxios/i', $userAgent)) {
            return 'Firefox';
        }
        if (preg_match('/safari/i', $userAgent)) {
            return 'Safari';
        }
        return 'Unknown';
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

