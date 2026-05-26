<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

/**
 * CaptureGeoInfo - Trait for capturing geographic information
 *
 * Extracts country, city, coordinates from IP address or headers.
 */
trait CaptureGeoInfo
{
    /**
     * Capture geographic information.
     */
    public function captureGeoInfo(?string $ipAddress = null): array
    {
        $ip = $ipAddress ?? (function_exists('request') ? request()->ip() : '0.0.0.0');

        return [
            'ip_address' => $ip,
            'country' => $this->getCountryFromIP($ip),
            'country_code' => $this->getCountryCodeFromIP($ip),
            'city' => $this->getCityFromIP($ip),
            'latitude' => $this->getLatitudeFromIP($ip),
            'longitude' => $this->getLongitudeFromIP($ip),
            'timezone' => $this->getTimezoneFromIP($ip),
        ];
    }

    /**
     * Get country name from IP address.
     * Override this in service class to use actual geo-location service.
     */
    protected function getCountryFromIP(string $ip): ?string
    {
        // This should be implemented in GeoLocation service
        return null;
    }

    /**
     * Get country code from IP address.
     */
    protected function getCountryCodeFromIP(string $ip): ?string
    {
        // This should be implemented in GeoLocation service
        return null;
    }

    /**
     * Get city from IP address.
     */
    protected function getCityFromIP(string $ip): ?string
    {
        // This should be implemented in GeoLocation service
        return null;
    }

    /**
     * Get latitude from IP address.
     */
    protected function getLatitudeFromIP(string $ip): ?float
    {
        // This should be implemented in GeoLocation service
        return null;
    }

    /**
     * Get longitude from IP address.
     */
    protected function getLongitudeFromIP(string $ip): ?float
    {
        // This should be implemented in GeoLocation service
        return null;
    }

    /**
     * Get timezone from IP address.
     */
    protected function getTimezoneFromIP(string $ip): ?string
    {
        // This should be implemented in GeoLocation service
        return null;
    }

    /**
     * Anonymize IP address for privacy.
     */
    public function anonymizeIP(string $ip, bool $anonymize = true): string
    {
        if (!$anonymize) {
            return $ip;
        }

        // For IPv4: replace last octet with .0
        if (strpos($ip, '.') !== false) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = '0';
                return implode('.', $parts);
            }
        }

        // For IPv6: replace last 80 bits with zeros
        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            if (count($parts) >= 4) {
                for ($i = 4; $i < count($parts); $i++) {
                    $parts[$i] = '0';
                }
                return implode(':', $parts);
            }
        }

        return $ip;
    }
}

