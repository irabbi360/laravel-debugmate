<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Irabbi360\LaravelDebugMate\Jobs\RegisterMonitorsJob;
use Irabbi360\LaravelDebugMate\Support\InternalLog;

class MonitorRegistrar
{
    public function __construct(
        protected ApiClient $apiClient,
        protected array $config = [],
    ) {}

    /**
     * Resolve configured monitor URLs (deduped, normalized).
     *
     * @return list<string>
     */
    public function resolveUrls(): array
    {
        $urls = $this->config['monitors']['urls'] ?? [];

        if (! is_array($urls)) {
            $urls = [];
        }

        if (($this->config['monitors']['include_app_url'] ?? true) && function_exists('config')) {
            $appUrl = config('app.url');
            if (is_string($appUrl) && $appUrl !== '') {
                $urls[] = $appUrl;
            }
        }

        $normalized = [];

        foreach ($urls as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $url = rtrim(trim($url), '/');

            if (! preg_match('#^https?://#i', $url)) {
                $url = 'https://'.$url;
            }

            $key = strtolower($url);
            if (! isset($normalized[$key])) {
                $normalized[$key] = $url;
            }
        }

        return array_values($normalized);
    }

    /**
     * Build monitor payloads for the API.
     *
     * @return list<array<string, mixed>>
     */
    public function buildMonitors(): array
    {
        $monitorConfig = $this->config['monitors'] ?? [];

        return array_map(fn (string $url) => [
            'url' => $url,
            'check_ssl' => (bool) ($monitorConfig['check_ssl'] ?? true),
            'check_domain' => (bool) ($monitorConfig['check_domain'] ?? true),
            'check_uptime' => (bool) ($monitorConfig['check_uptime'] ?? true),
            'expected_status' => (int) ($monitorConfig['expected_status'] ?? 200),
            'timeout_seconds' => (int) ($monitorConfig['timeout_seconds'] ?? 10),
        ], $this->resolveUrls());
    }

    /**
     * Sync monitors to DebugMate (sync or queued).
     */
    public function sync(bool $async = false): bool
    {
        if (! ($this->config['enabled'] ?? true) || ! ($this->config['track_monitors'] ?? false)) {
            return false;
        }

        $monitors = $this->buildMonitors();

        if ($monitors === []) {
            InternalLog::warning('DebugMate: No monitor URLs configured to register');

            return false;
        }

        $payload = [
            'monitors' => $monitors,
            'replace' => (bool) (($this->config['monitors']['replace'] ?? false)),
        ];

        if ($async && ($this->config['async_reporting'] ?? false)) {
            $pending = dispatch(new RegisterMonitorsJob($payload))
                ->onQueue((string) (config('debugmate.queue_name') ?: 'default'));

            if ($connection = config('debugmate.queue_connection')) {
                $pending->onConnection($connection);
            }

            unset($pending); // dispatches now, inside this method

            return true;
        }

        return $this->apiClient->registerMonitors($payload);
    }
}
