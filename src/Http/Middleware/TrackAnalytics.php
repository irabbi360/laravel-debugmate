<?php

namespace Irabbi360\LaravelDebugMate\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Irabbi360\LaravelDebugMate\DTO\DeviceDataDTO;
use Irabbi360\LaravelDebugMate\DTO\GeoLocationDataDTO;
use Irabbi360\LaravelDebugMate\Services\BotDetector;
use Irabbi360\LaravelDebugMate\Services\GeoLocation;
use Irabbi360\LaravelDebugMate\Services\RequestAnalytics;
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
        // Check if analytics tracking is enabled
        if (!config('debugmate.track_analytics', false)) {
            return $next($request);
        }

        // Check if path should be ignored
        if ($this->shouldIgnorePath($request->path())) {
            return $next($request);
        }

        // Check if it's a bot request
        $isBot = $this->botDetector->isBot($request->userAgent());
        if ($isBot && config('debugmate.exclude_bots', true)) {
            return $next($request);
        }

        // Parse user agent to extract browser, OS, and device info
        $userAgentInfo = $this->parseUserAgent($request->userAgent());

        // Create Device DTO
        $deviceData = DeviceDataDTO::from($userAgentInfo);

        // Anonymize IP if configured
        $ipAddress = $request->ip();
        if (config('debugmate.anonymize_ips', true)) {
            $ipAddress = $this->anonymizeIp($ipAddress);
        }

        // Get geolocation data from IP (if enabled)
        $geoArray = $this->geoLocation->getLocationByIp($request->ip() ?? '');

        // Create GeoLocation DTO
        $geoData = GeoLocationDataDTO::from($geoArray);

        // Collect comprehensive request data
        $requestData = [
            'user_agent' => $request->userAgent(),
            'ip_address' => $ipAddress,
            'referrer' => $this->analytics->resolveExternalReferrer($request->headers->get('referer')),
            'accept_language' => $request->header('Accept-Language'),
            'user_id' => auth()->id(),
            // Browser info from DTO
            'browser' => $deviceData->browser,
            'browser_version' => $deviceData->browser_version,
            // OS info from DTO
            'os' => $deviceData->os,
            'os_version' => $deviceData->os_version,
            // Device info from DTO
            'device_type' => $deviceData->device_type,
            'device_name' => $deviceData->device_name,
            // Geo info from DTO
            'country' => $geoData->country,
            'country_code' => $geoData->country_code,
            'city' => $geoData->city,
            'latitude' => $geoData->latitude,
            'longitude' => $geoData->longitude,
        ];

        $existingSessionId = $request->cookie(self::SESSION_COOKIE);
        if ($existingSessionId && $this->analytics->resumeSession($existingSessionId, $requestData)) {
            $sessionId = $existingSessionId;
        } else {
            $sessionId = $this->analytics->startSession($requestData);
        }

        $request->attributes->set('debugmate_session_id', $sessionId);
        $request->attributes->set('debugmate_is_bot', $isBot);

        $response = $next($request);

        $loadTimeMs = defined('LARAVEL_START')
            ? (int) round((microtime(true) - LARAVEL_START) * 1000)
            : null;

        try {
            $this->analytics->trackPageView([
                'url' => $request->fullUrl(),
                'title' => $request->header('X-Page-Title') ?? $request->route()?->getName(),
                'referrer' => $this->analytics->resolveExternalReferrer($request->headers->get('referer')),
                'load_time_ms' => $loadTimeMs,
                'status_code' => $response->getStatusCode(),
                'method' => $request->method(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('DebugMate: Failed to track page view', ['error' => $e->getMessage()]);
        }

        try {
            $this->analytics->updateSession($response->getStatusCode());
        } catch (\Throwable $e) {
            Log::debug('DebugMate: Failed to update analytics session', ['error' => $e->getMessage()]);
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
     * Parse user agent to extract browser, OS, and device info.
     */
    protected function parseUserAgent(string $userAgent): array
    {
        $info = [
            'browser' => null,
            'browser_version' => null,
            'os' => null,
            'os_version' => null,
            'device_type' => 'desktop',
            'device_name' => null,
        ];

        // Detect browser
        if (preg_match('/Chrome\/(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'Chrome';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/Firefox\/(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'Firefox';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/Safari\/(\d+)/', $userAgent, $matches) && !preg_match('/Chrome/', $userAgent)) {
            $info['browser'] = 'Safari';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/MSIE (\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'IE';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/Trident.*rv:(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'IE';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/Edge\/(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'Edge';
            $info['browser_version'] = $matches[1];
        } elseif (preg_match('/OPR\/(\d+)/', $userAgent, $matches)) {
            $info['browser'] = 'Opera';
            $info['browser_version'] = $matches[1];
        }

        // Detect OS
        if (preg_match('/Windows NT (\d+\.\d+)/', $userAgent, $matches)) {
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

        // Detect device type
        if (preg_match('/(Mobile|Android|iPhone|iPad|iPod|BlackBerry|Windows Phone)/', $userAgent)) {
            if (preg_match('/(iPad|Android)/', $userAgent)) {
                $info['device_type'] = 'tablet';
            } else {
                $info['device_type'] = 'mobile';
            }
        }

        // Detect device name
        if (preg_match('/iPhone/', $userAgent)) {
            $info['device_name'] = 'iPhone';
        } elseif (preg_match('/iPad/', $userAgent)) {
            $info['device_name'] = 'iPad';
        } elseif (preg_match('/Android/', $userAgent)) {
            $info['device_name'] = 'Android Device';
        }

        return $info;
    }

    /**
     * Anonymize IP address.
     */
    protected function anonymizeIp(string $ip): string
    {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';
            return implode('.', $parts);
        }
        return $ip;
    }

    /**
     * Check if path should be ignored from analytics.
     */
    protected function shouldIgnorePath(string $path): bool
    {
        $ignorePaths = config('debugmate.ignore_paths', []);

        foreach ($ignorePaths as $ignorePath) {
            // Handle wildcards
            $pattern = str_replace('*', '.*', $ignorePath);
            if (preg_match("~^{$pattern}$~", $path)) {
                return true;
            }
        }

        return false;
    }
}

