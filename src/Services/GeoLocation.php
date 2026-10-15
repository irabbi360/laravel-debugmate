<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Irabbi360\LaravelDebugMate\Support\InternalLog;

/**
 * GeoLocation Service
 *
 * Provides IP geolocation lookup to identify visitor country and city.
 * Uses the configured endpoint (debugmate.geolocation.endpoint, ip-api.com by
 * default) and caches results to minimize API calls.
 */
class GeoLocation
{
    protected const CACHE_TTL = 86400 * 30; // 30 days
    protected const FALLBACK_COUNTRY = 'Unknown';
    protected const API_ENDPOINT = 'http://ip-api.com/json/';

    /**
     * Get geolocation data for an IP address.
     */
    public function getLocationByIp(string $ip): array
    {
        // Return empty if IP is invalid or private/loopback
        if ($this->isPrivateIp($ip)) {
            return [
                'country' => null,
                'country_code' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        // If geolocation is disabled, return empty
        if (! config('debugmate.enable_geolocation', false)) {
            return [
                'country' => null,
                'country_code' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        $cacheKey = 'debugmate_geo_'.hash('sha256', $ip);

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            $endpoint = (string) config('debugmate.geolocation.endpoint', self::API_ENDPOINT);
            $timeout = (float) config('debugmate.geolocation.timeout', 1);

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->get(rtrim($endpoint, '/').'/'.rawurlencode($ip));

            if ($response->successful() && $response->json('status') === 'success') {
                $data = $response->json();

                $result = [
                    'country' => $data['country'] ?? self::FALLBACK_COUNTRY,
                    'country_code' => strtoupper($data['countryCode'] ?? ''),
                    'city' => $data['city'] ?? null,
                    'latitude' => $data['lat'] ?? null,
                    'longitude' => $data['lon'] ?? null,
                ];

                // Cache the result
                Cache::put($cacheKey, $result, self::CACHE_TTL);

                return $result;
            }
        } catch (\Throwable $e) {
            InternalLog::debug('DebugMate: Failed to lookup IP geolocation', [
                'error' => $e->getMessage(),
            ]);
        }

        // Cache the miss briefly so a slow or down provider doesn't add its
        // timeout to every request.
        try {
            Cache::put($cacheKey, [
                'country' => null,
                'country_code' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
            ], 600);
        } catch (\Throwable) {
            // cache unavailable - ignore
        }

        return [
            'country' => null,
            'country_code' => null,
            'city' => null,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /**
     * Check if IP is private/loopback.
     */
    protected function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}

