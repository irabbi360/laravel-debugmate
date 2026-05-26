<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * GeoLocation Service
 *
 * Provides IP geolocation lookup to identify visitor country and city.
 * Uses ip-api.com free tier for lookups and caches results to minimize API calls.
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
        // Return empty if IP is private/loopback
        if ($this->isPrivateIp($ip)) {
            return [
                'country' => null,
                'country_code' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        // Check cache first
        $cacheKey = "debugmate_geo_{$ip}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        // If geolocation is disabled, return empty
        if (!config('debugmate.enable_geolocation', false)) {
            return [
                'country' => null,
                'country_code' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        try {
            // Lookup from free IP geolocation API
            $response = Http::timeout(2)->get(self::API_ENDPOINT . $ip);

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
            Log::debug('DebugMate: Failed to lookup IP geolocation', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        // Return empty on failure
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

