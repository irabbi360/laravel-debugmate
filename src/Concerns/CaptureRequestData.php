<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

/**
 * CaptureRequestData - Trait for capturing HTTP request information
 *
 * Extracts request data like method, path, headers, status code, etc.
 */
trait CaptureRequestData
{
    // Provided by CaptureSanitization, which every user of this trait must also use.
    abstract public function sanitizeData(array $data, int $depth = 0): array;

    abstract public function sanitizeUrl(?string $url): ?string;

    abstract public function sanitizeQueryString(?string $query): ?string;

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
            'full_url' => $this->sanitizeUrl(request()->fullUrl()),
            'query_string' => $this->sanitizeQueryString(request()->getQueryString()),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'accept_language' => request()->header('Accept-Language'),
            'referrer' => $this->sanitizeUrl(request()->header('Referer')),
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

        $headers = [];

        foreach (request()->headers->all() as $key => $value) {
            $headers[$key] = is_array($value) ? implode(', ', $value) : $value;
        }

        if (isset($headers['referer'])) {
            $headers['referer'] = $this->sanitizeUrl($headers['referer']);
        }

        // Drops authorization, cookie, php-auth-*, x-*-token, x-api-key, ...
        return array_filter(
            $this->sanitizeData($headers),
            fn ($value) => $value !== '***SANITIZED***'
        );
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
        try {
            $user = auth()->user();
        } catch (\Throwable) {
            $user = null;
        }

        $sendPii = (bool) config('debugmate.send_user_pii', true);

        return [
            'request_data' => $this->captureRequestData(),
            'user_id' => $user?->getAuthIdentifier(),
            'user_name' => $sendPii ? ($user->name ?? null) : null,
            'user_email' => $sendPii ? ($user->email ?? null) : null,
        ];
    }
}

