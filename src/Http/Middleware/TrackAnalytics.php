<?php

namespace Irabbi360\LaravelDebugMate\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Irabbi360\LaravelDebugMate\DTO\DeviceDataDTO;
use Irabbi360\LaravelDebugMate\DTO\GeoLocationDataDTO;
use Irabbi360\LaravelDebugMate\Services\BotDetector;
use Irabbi360\LaravelDebugMate\Services\GeoLocation;
use Irabbi360\LaravelDebugMate\Services\RequestAnalytics;
use Irabbi360\LaravelDebugMate\Support\InternalLog;
use Irabbi360\LaravelDebugMate\Support\IpAnonymizer;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Symfony\Component\HttpFoundation\Response;

class TrackAnalytics
{
    protected const SESSION_COOKIE = 'debugmate_sid';

    protected const SESSION_MINUTES = 30;

    protected RequestAnalytics $analytics;

    protected BotDetector $botDetector;

    protected GeoLocation $geoLocation;

    public function __construct(RequestAnalytics $analytics, BotDetector $botDetector, GeoLocation $geoLocation)
    {
        $this->analytics = $analytics;
        $this->botDetector = $botDetector;
        $this->geoLocation = $geoLocation;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('debugmate.track_analytics', false) || $this->shouldIgnorePath($request->path())) {
            return $next($request);
        }

        // Analytics is best-effort: a failure here must never fail the request.
        $sessionId = null;

        try {
            $sessionId = $this->beginTracking($request);
        } catch (\Throwable $e) {
            InternalLog::debug('DebugMate: Failed to start analytics tracking', ['error' => $e->getMessage()]);
        }

        $response = $next($request);

        if ($sessionId === null) {
            return $response;
        }

        $loadTimeMs = defined('LARAVEL_START')
            ? (int) round((microtime(true) - LARAVEL_START) * 1000)
            : null;

        try {
            $this->analytics->trackPageView([
                'url' => $this->analytics->sanitizeUrl($request->fullUrl()),
                'title' => Str::limit((string) ($request->header('X-Page-Title') ?? $request->route()?->getName() ?? ''), 255, '') ?: null,
                'referrer' => $this->analytics->resolveExternalReferrer($request->headers->get('referer')),
                'load_time_ms' => $loadTimeMs,
                'status_code' => $response->getStatusCode(),
                'method' => $request->method(),
            ]);
        } catch (\Throwable $e) {
            InternalLog::debug('DebugMate: Failed to track page view', ['error' => $e->getMessage()]);
        }

        try {
            $this->analytics->updateSession($response->getStatusCode());
        } catch (\Throwable $e) {
            InternalLog::debug('DebugMate: Failed to update analytics session', ['error' => $e->getMessage()]);
        }

        $response->headers->setCookie(
            Cookie::make(
                self::SESSION_COOKIE,
                $sessionId,
                self::SESSION_MINUTES,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                SymfonyCookie::SAMESITE_LAX
            )
        );

        return $response;
    }

    /**
     * Start or resume the analytics session. Returns null when the request is
     * not tracked (bots).
     */
    protected function beginTracking(Request $request): ?string
    {
        $userAgent = (string) ($request->userAgent() ?? '');

        $isBot = $this->botDetector->isBot($userAgent);
        if ($isBot && config('debugmate.analytics.exclude_bots', true)) {
            return null;
        }

        $deviceData = DeviceDataDTO::from($this->parseUserAgent($userAgent));

        $ipAddress = $request->ip();
        if (config('debugmate.analytics.anonymize_ips', true)) {
            $ipAddress = IpAnonymizer::anonymize($ipAddress);
        }

        // The (anonymized) IP is what leaves the app - including to the
        // geolocation provider. A /24 still resolves country and city.
        $geoData = GeoLocationDataDTO::from($this->geoLocation->getLocationByIp((string) $ipAddress));

        $requestData = [
            'user_agent' => Str::limit($userAgent, 1024, ''),
            'ip_address' => $ipAddress,
            'referrer' => $this->analytics->resolveExternalReferrer($request->headers->get('referer')),
            'accept_language' => $request->header('Accept-Language'),
            'user_id' => $this->safeUserId(),
            'browser' => $deviceData->browser,
            'browser_version' => $deviceData->browser_version,
            'os' => $deviceData->os,
            'os_version' => $deviceData->os_version,
            'device_type' => $deviceData->device_type,
            'device_name' => $deviceData->device_name,
            'country' => $geoData->country,
            'country_code' => $geoData->country_code,
            'city' => $geoData->city,
            'latitude' => $geoData->latitude,
            'longitude' => $geoData->longitude,
        ];

        // The cookie is client-controlled: only accept a well-formed UUID.
        $existingSessionId = $request->cookie(self::SESSION_COOKIE);
        if (is_string($existingSessionId)
            && Str::isUuid($existingSessionId)
            && $this->analytics->resumeSession($existingSessionId, $requestData)) {
            $sessionId = $existingSessionId;
        } else {
            $sessionId = $this->analytics->startSession($requestData);
        }

        $request->attributes->set('debugmate_session_id', $sessionId);
        $request->attributes->set('debugmate_is_bot', $isBot);

        return $sessionId;
    }

    protected function safeUserId(): mixed
    {
        try {
            return auth()->id();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse user agent to extract browser, OS, and device info.
     */
    protected function parseUserAgent(?string $userAgent): array
    {
        $userAgent = (string) $userAgent;

        $info = [
            'browser' => null,
            'browser_version' => null,
            'os' => null,
            'os_version' => null,
            'device_type' => 'desktop',
            'device_name' => null,
        ];

        // Detect browser. Order matters: Edge and Opera also send "Chrome/",
        // and Chrome also sends "Safari/".
        if (preg_match('/Edg(?:e|A|iOS)?\/(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'Edge';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/OPR\/(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'Opera';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/(?:Chrome|CriOS)\/(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'Chrome';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/(?:Firefox|FxiOS)\/(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'Firefox';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/Version\/(\d+).*Safari\//', $userAgent, $matches)) {
            $info['browser'] = 'Safari';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/MSIE (\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'IE';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/Trident.*rv:(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'IE';
            $info['browser_version'] = $matches[1];
        }

        // Detect OS. iOS / Android must be checked before macOS / Linux.
        if (preg_match('/(?:iPhone|iPad|iPod).*OS ([\d_]+)/', $userAgent, $matches)) {
            $info['os'] = 'iOS';
            $info['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Android ([\d.]+)/', $userAgent, $matches)) {
            $info['os'] = 'Android';
            $info['os_version'] = $matches[1];
        } elseif (preg_match('/Windows NT (\d+\.\d+)/', $userAgent, $matches)) {
            $info['os'] = 'Windows';
            $info['os_version'] = $matches[1];
        } elseif (preg_match('/Mac OS X ([\d_]+)/', $userAgent, $matches)) {
            $info['os'] = 'macOS';
            $info['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Ubuntu/', $userAgent)) {
            $info['os'] = 'Ubuntu';
        } elseif (preg_match('/Linux/', $userAgent)) {
            $info['os'] = 'Linux';
        }

        // Detect device type. Android phones send "Mobile"; Android tablets don't.
        if (preg_match('/iPad|Tablet/i', $userAgent)
            || (str_contains($userAgent, 'Android') && ! str_contains($userAgent, 'Mobile'))) {
            $info['device_type'] = 'tablet';
        } elseif (preg_match('/Mobile|Android|iPhone|iPod|BlackBerry|Windows Phone/', $userAgent)) {
            $info['device_type'] = 'mobile';
        }

        // Detect device name
        if (str_contains($userAgent, 'iPhone')) {
            $info['device_name'] = 'iPhone';
        } elseif (str_contains($userAgent, 'iPad')) {
            $info['device_name'] = 'iPad';
        } elseif (str_contains($userAgent, 'Android')) {
            $info['device_name'] = 'Android Device';
        }

        return $info;
    }

    /**
     * Anonymize IP address (IPv4 and IPv6).
     */
    protected function anonymizeIp(string $ip): string
    {
        return (string) IpAnonymizer::anonymize($ip);
    }

    /**
     * Check if path should be ignored from analytics.
     */
    protected function shouldIgnorePath(string $path): bool
    {
        foreach ((array) config('debugmate.ignore_paths', []) as $ignorePath) {
            if (is_string($ignorePath) && Str::is(trim($ignorePath, '/'), trim($path, '/'))) {
                return true;
            }
        }

        return false;
    }
}
