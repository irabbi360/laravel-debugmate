<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Irabbi360\LaravelDebugMate\Jobs\ReportAnalyticsJob;
use Irabbi360\LaravelDebugMate\DTO\AnalyticsDataDTO;
use Irabbi360\LaravelDebugMate\Concerns\CaptureRequestData;
use Irabbi360\LaravelDebugMate\Concerns\CaptureDeviceInfo;
use Irabbi360\LaravelDebugMate\Concerns\CaptureGeoInfo;
use Irabbi360\LaravelDebugMate\Concerns\AsyncDispatch;

class RequestAnalytics
{
    use CaptureRequestData;
    use CaptureDeviceInfo;
    use CaptureGeoInfo;
    use AsyncDispatch;

    protected ApiClient $apiClient;
    protected array $config;
    protected ?string $sessionId = null;
    protected ?string $userFingerprint = null;
    protected array $sessionData = [];
    protected const SESSION_CACHE_TTL = 86400; // 24 hours

    public function __construct(ApiClient $apiClient, array $config)
    {
        $this->apiClient = $apiClient;
        $this->config = $config;
    }

    /**
     * Start a new analytics session for current request using DTO.
     */
    public function startSession(array $requestData): string
    {
        $this->sessionId = (string)Str::uuid();

        // Capture device and geo information
        $deviceInfo = $this->captureDeviceInfo($requestData['user_agent'] ?? null);
        $geoInfo = $this->captureGeoInfo($requestData['ip_address'] ?? null);

        $this->sessionData = [
            'session_id' => $this->sessionId,
            'project_key' => $this->config['debugmate_key'] ?? 'unknown',
            'user_agent' => $requestData['user_agent'] ?? '',
            'ip_address' => $requestData['ip_address'] ?? '',
            'referrer' => $requestData['referrer'] ?? null,
            'user_id' => $requestData['user_id'] ?? null,
            // Device info from trait
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'browser_version' => $deviceInfo['browser_version'],
            'os' => $deviceInfo['os'],
            'os_version' => $deviceInfo['os_version'],
            // Geo info from trait
            'country' => $geoInfo['country'],
            'country_code' => $geoInfo['country_code'],
            'city' => $geoInfo['city'],
            'latitude' => $geoInfo['latitude'],
            'longitude' => $geoInfo['longitude'],
            // Metrics
            'page_views' => 0,
            'bounce_count' => 0,
            'pages_visited' => [],
            'session_start_time' => microtime(true),
        ];

        // Generate anonymized fingerprint
        $this->userFingerprint = $this->generateUserFingerprint($requestData);
        $this->sessionData['user_fingerprint'] = $this->userFingerprint;

        // Store session in cache for tracking across requests
        $this->storeSessionInCache($this->sessionId, $this->sessionData);

        return $this->sessionId;
    }

    /**
     * Resume an existing analytics session from cache.
     */
    public function resumeSession(string $sessionId, array $requestData = []): bool
    {
        $cachedSession = $this->getSessionFromCache($sessionId);
        if ($cachedSession === null) {
            return false;
        }

        $this->sessionId = $sessionId;
        $this->sessionData = $cachedSession;
        $this->userFingerprint = $cachedSession['user_fingerprint'] ?? null;

        if (isset($requestData['user_id']) && $requestData['user_id']) {
            $this->sessionData['user_id'] = $requestData['user_id'];
        }

        return true;
    }

    /**
     * Report from AnalyticsDataDTO.
     */
    public function reportFromDTO(AnalyticsDataDTO $dto): void
    {
        $data = $dto->toArray();

        if ($this->config['async_reporting'] ?? false) {
            $this->dispatchAnalyticsReport($data);
        } else {
            $this->apiClient->reportAnalytics($data);
        }
    }

    /**
     * Track a page view using DTO.
     */
    public function trackPageView(array $pageData): void
    {
        if (!$this->sessionId) {
            return;
        }

        // Get current page
        $currentPage = $pageData['url'] ?? request()->url();

        // Restore session from cache to get real data
        $cachedSession = $this->getSessionFromCache($this->sessionId);
        if ($cachedSession) {
            $this->sessionData = $cachedSession;
        }

        // Increment page views
        $this->sessionData['page_views'] = ($this->sessionData['page_views'] ?? 0) + 1;

        // Track pages visited for bounce detection
        if (!isset($this->sessionData['pages_visited'])) {
            $this->sessionData['pages_visited'] = [];
        }
        if (!in_array($currentPage, $this->sessionData['pages_visited'])) {
            $this->sessionData['pages_visited'][] = $currentPage;
        }

        // Update cache
        $this->storeSessionInCache($this->sessionId, $this->sessionData);

        // Calculate session duration
        $duration = microtime(true) - ($this->sessionData['session_start_time'] ?? microtime(true));

        // Create analytics DTO
        $analyticsData = AnalyticsDataDTO::from(array_merge($this->sessionData, [
            'type' => 'page_view',
            'page_url' => $currentPage,
            'page_title' => $pageData['title'] ?? null,
            'referrer' => $pageData['referrer'] ?? $this->resolveExternalReferrer($this->sessionData['referrer'] ?? null),
            'load_time_ms' => $pageData['load_time_ms'] ?? null,
            'method' => $pageData['method'] ?? request()->method(),
            'status_code' => $pageData['status_code'] ?? 200,
            'session_duration_seconds' => round($duration),
        ]));

        $this->reportFromDTO($analyticsData);
    }

    /**
     * Track a custom event using DTO.
     */
    public function trackEvent(string $eventName, array $eventData = [], string $category = null): void
    {
        if (!$this->sessionId) {
            return;
        }

        // Restore session from cache
        $cachedSession = $this->getSessionFromCache($this->sessionId);
        if ($cachedSession) {
            $this->sessionData = $cachedSession;
        }

        // Create analytics DTO
        $analyticsData = AnalyticsDataDTO::from(array_merge($this->sessionData, [
            'type' => 'event',
            'event_name' => $eventName,
            'event_data' => $eventData,
            'event_category' => $category ?? 'custom',
            'page_url' => request()->url(),
        ]));

        $this->reportFromDTO($analyticsData);
    }

    /**
     * Update the current session metrics without ending it.
     */
    public function updateSession(int $statusCode = 200): void
    {
        if (! $this->sessionId) {
            return;
        }

        $cachedSession = $this->getSessionFromCache($this->sessionId);
        if ($cachedSession) {
            $this->sessionData = $cachedSession;
        }

        $duration = microtime(true) - ($this->sessionData['session_start_time'] ?? microtime(true));
        $pageViews = (int) ($this->sessionData['page_views'] ?? 0);
        $bounceCount = ($pageViews <= 1 && $duration < 30) ? 1 : 0;

        $analyticsData = AnalyticsDataDTO::from(array_merge($this->sessionData, [
            'type' => 'session',
            'session_duration_seconds' => round($duration),
            'status_code' => $statusCode,
            'bounce_count' => $bounceCount,
            'page_views' => $pageViews,
        ]));

        $this->reportFromDTO($analyticsData);
        $this->sessionData['bounce_count'] = $bounceCount;
        $this->storeSessionInCache($this->sessionId, $this->sessionData);
    }

    /**
     * End current session and record metrics using DTO.
     */
    public function endSession(int $statusCode = 200): void
    {
        if (! $this->sessionId) {
            return;
        }

        $this->updateSession($statusCode);
        $this->deleteSessionFromCache($this->sessionId);
        $this->sessionId = null;
    }

    /**
     * Generate anonymized user fingerprint.
     */
    protected function generateUserFingerprint(array $requestData): string
    {
        $components = [
            $requestData['user_agent'] ?? '',
            $this->anonymizeIp($requestData['ip_address'] ?? ''),
            $requestData['accept_language'] ?? '',
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Anonymize IP address (keep first 3 octets).
     */
    protected function anonymizeIp(string $ip): string
    {
        if ($this->config['anonymize_ips'] ?? false) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = '0';
                return implode('.', $parts);
            }
        }

        return $ip;
    }

    /**
     * Get current page URL for tracking.
     */
    protected function getCurrentPage(): string
    {
        return request()->url();
    }

    /**
     * Store session data in cache.
     */
    protected function storeSessionInCache(string $sessionId, array $data): void
    {
        try {
            Cache::put("debugmate_session_{$sessionId}", $data, self::SESSION_CACHE_TTL);
        } catch (\Throwable $e) {
            Log::debug('DebugMate: Failed to store session in cache', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get session data from cache.
     */
    protected function getSessionFromCache(string $sessionId): ?array
    {
        try {
            return Cache::get("debugmate_session_{$sessionId}");
        } catch (\Throwable $e) {
            Log::debug('DebugMate: Failed to get session from cache', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Delete session data from cache.
     */
    protected function deleteSessionFromCache(string $sessionId): void
    {
        try {
            Cache::forget("debugmate_session_{$sessionId}");
        } catch (\Throwable $e) {
            Log::debug('DebugMate: Failed to delete session from cache', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send analytics data to API (async or sync).
     */
    protected function sendAnalytics(string $type, array $payload): void
    {
        if (!($this->config['send_analytics_to_api'] ?? true)) {
            return;
        }

        $async = $this->config['async_reporting'] ?? false;

        if ($async) {
            try {
                $this->dispatchAnalyticsReport($payload);
                return;
            } catch (\Throwable $e) {
                Log::error('DebugMate: Failed to dispatch analytics job', ['error' => $e->getMessage()]);
            }
        }

        try {
            $this->apiClient->reportAnalytics($payload);
        } catch (\Throwable $e) {
            Log::debug('DebugMate: Failed to send analytics', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get current session ID.
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Get current user fingerprint.
     */
    public function getUserFingerprint(): ?string
    {
        return $this->userFingerprint;
    }
}



