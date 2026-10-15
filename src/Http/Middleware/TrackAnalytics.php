<?php

namespace Irabbi360\LaravelDebugMate\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
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

    /** @var (Closure(Request): bool)|null */
    protected static ?Closure $consentResolver = null;

    /**
     * Decide per request whether the visitor consented to analytics, e.g.
     * TrackAnalytics::resolveConsentUsing(fn (Request $r) => $r->cookie('cookie_consent') === 'all');
     * Pass null to remove the resolver.
     */
    public static function resolveConsentUsing(?Closure $resolver): void
    {
        static::$consentResolver = $resolver;
    }

    public function __construct(RequestAnalytics $analytics, BotDetector $botDetector, GeoLocation $geoLocation)
    {
        $this->analytics = $analytics;
        $this->botDetector = $botDetector;
        $this->geoLocation = $geoLocation;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('debugmate.track_analytics', false)
            || ! $this->isTrackablePageRequest($request)
            || $this->shouldIgnorePath($request->path())
            || ! $this->hasConsent($request)) {
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
                'url' => $this->analytics->sanitizeRequestUrl($request),
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

        $ipAddress = $request->ip();
        if (config('debugmate.analytics.anonymize_ips', true)) {
            $ipAddress = IpAnonymizer::anonymize($ipAddress);
        }

        // Returning visitor: continue the session. No cache writes, geo lookups
        // or rate-limit checks beyond the session lookup itself.
        $existingSessionId = $request->cookie(self::SESSION_COOKIE);
        if (is_string($existingSessionId)
            && Str::isUuid($existingSessionId)
            && $this->analytics->resumeSession($existingSessionId, ['user_id' => $this->safeUserId()])) {
            $request->attributes->set('debugmate_session_id', $existingSessionId);
            $request->attributes->set('debugmate_is_bot', $isBot);

            return $existingSessionId;
        }

        // New session: every one costs a cache entry and API reports, so
        // sample and rate-limit them - cookieless clients (scripts, scanners)
        // would otherwise create one per request.
        if (! $this->passesSampleRate() || ! $this->withinNewSessionLimit((string) $ipAddress)) {
            return null;
        }

        $deviceData = DeviceDataDTO::from($this->parseUserAgent($userAgent));

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

        $sessionId = $this->analytics->startSession($requestData);

        $request->attributes->set('debugmate_session_id', $sessionId);
        $request->attributes->set('debugmate_is_bot', $isBot);

        return $sessionId;
    }

    /**
     * Only real page loads are analytics: GET, not JSON / XHR API calls, not
     * browser prefetches. (Inertia visits send Accept: text/html and count.)
     */
    protected function isTrackablePageRequest(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return false;
        }

        $purpose = strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose') ?? ''));

        return ! str_contains($purpose, 'prefetch') && ! str_contains($purpose, 'prerender');
    }

    protected function hasConsent(Request $request): bool
    {
        if (static::$consentResolver !== null) {
            try {
                return (bool) (static::$consentResolver)($request);
            } catch (\Throwable) {
                return false;
            }
        }

        $consentCookie = config('debugmate.analytics.consent_cookie');

        if (! is_string($consentCookie) || $consentCookie === '') {
            return true;
        }

        $value = $request->cookie($consentCookie);

        return $value !== null && ! in_array(strtolower((string) $value), ['', '0', 'false', 'no', 'deny', 'denied'], true);
    }

    protected function passesSampleRate(): bool
    {
        $rate = (float) config('debugmate.analytics.sample_rate', 1.0);

        return $rate >= 1.0 || (mt_rand() / mt_getrandmax()) < $rate;
    }

    protected function withinNewSessionLimit(string $ipAddress): bool
    {
        $limit = (int) config('debugmate.analytics.max_new_sessions_per_minute', 30);

        if ($limit <= 0) {
            return true;
        }

        try {
            return RateLimiter::attempt('debugmate-analytics:'.sha1($ipAddress), $limit, fn () => true, 60) !== false;
        } catch (\Throwable) {
            return true; // cache unavailable - don't block tracking on it
        }
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
