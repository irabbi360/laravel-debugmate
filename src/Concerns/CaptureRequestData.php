<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

/**
 * CaptureRequestData - Trait for capturing HTTP request information
 *
 * Extracts request data like method, path, headers, status code, etc.
 */
trait CaptureRequestData
{
    /**
     * Capture request data from current request.
     */
    public function captureRequestData(): array
    {
        if (!function_exists('request') || !request()) {
            return [];
        }

        return [
            'method' => request()->method(),
            'path' => request()->path(),
            'url' => request()->url(),
            'full_url' => request()->fullUrl(),
            'query_string' => request()->getQueryString(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'accept_language' => request()->header('Accept-Language'),
            'referrer' => request()->header('Referer') ?? request()->referrer() ?? null,
            'headers' => $this->getSafeHeaders(),
        ];
    }

    /**
     * Get safe headers (exclude sensitive ones).
     */
    protected function getSafeHeaders(): array
    {
        if (!function_exists('request') || !request()) {
            return [];
        }

        $sensitiveKeys = ['authorization', 'cookie', 'x-api-token', 'x-csrf-token', 'x-app-token'];
        $headers = request()->headers->all();
        $safeHeaders = [];

        foreach ($headers as $key => $value) {
            if (!in_array(strtolower($key), $sensitiveKeys)) {
                $safeHeaders[$key] = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return $safeHeaders;
    }

    /**
     * Keep only external referring sites (exclude same-app navigation).
     */
    public function resolveExternalReferrer(?string $referrer, ?string $siteHost = null): ?string
    {
        if ($referrer === null || trim($referrer) === '') {
            return null;
        }

        $referrerHost = parse_url($referrer, PHP_URL_HOST);
        if (! is_string($referrerHost) || $referrerHost === '') {
            return null;
        }

        $siteHost = $siteHost ?? (function_exists('request') && request() ? request()->getHost() : null);
        if ($siteHost && $this->referrerHostsMatch($referrerHost, $siteHost)) {
            return null;
        }

        $scheme = parse_url($referrer, PHP_URL_SCHEME) ?: 'https';

        return strtolower($scheme.'://'.$referrerHost);
    }

    protected function referrerHostsMatch(string $referrerHost, string $siteHost): bool
    {
        $normalize = static fn (string $host): string => strtolower(preg_replace('/^www\./', '', $host));

        return $normalize($referrerHost) === $normalize($siteHost);
    }

    /**
     * Get request context data.
     */
    public function captureRequestContext(): array
    {
        return [
            'request_data' => $this->captureRequestData(),
            'user_id' => auth()->id() ?? null,
            'user_name' => auth()->user()?->name ?? null,
            'user_email' => auth()->user()?->email ?? null,
        ];
    }
}

