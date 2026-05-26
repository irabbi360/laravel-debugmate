<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * GeoLocationDataDTO - Data Transfer Object for Geographic Information
 *
 * Encapsulates geographic data obtained from IP geolocation.
 * Provides helper methods for location-based filtering and grouping.
 */
class GeoLocationDataDTO implements Arrayable, JsonSerializable
{
    /**
     * Create a new GeoLocationDataDTO instance.
     */
    public function __construct(
        public readonly ?string $country = null,
        public readonly ?string $country_code = null,
        public readonly ?string $city = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
    ) {}

    /**
     * Create GeoLocationDataDTO from array.
     */
    public static function from(array $data): self
    {
        return new self(
            country: $data['country'] ?? null,
            country_code: $data['country_code'] ?? null,
            city: $data['city'] ?? null,
            latitude: $data['latitude'] ?? null,
            longitude: $data['longitude'] ?? null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    /**
     * Convert to JSON.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Check if location data is available.
     */
    public function hasLocation(): bool
    {
        return !empty($this->country_code) || !empty($this->city);
    }

    /**
     * Check if coordinates are available.
     */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Get full location string.
     */
    public function getFullLocation(): string
    {
        $parts = [];

        if ($this->city) {
            $parts[] = $this->city;
        }

        if ($this->country) {
            $parts[] = $this->country;
        }

        if (!empty($parts)) {
            return implode(', ', $parts);
        }

        if ($this->country_code) {
            return $this->country_code;
        }

        return 'Unknown Location';
    }

    /**
     * Check if from specific country.
     */
    public function isFromCountry(string|array $countries): bool
    {
        if (is_string($countries)) {
            return strtoupper($this->country_code ?? '') === strtoupper($countries);
        }

        return in_array(strtoupper($this->country_code ?? ''), array_map('strtoupper', $countries));
    }

    /**
     * Check if from United States.
     */
    public function isFromUS(): bool
    {
        return $this->isFromCountry('US');
    }

    /**
     * Check if from Europe (simplified).
     */
    public function isFromEurope(): bool
    {
        $europeanCountries = ['GB', 'FR', 'DE', 'IT', 'ES', 'NL', 'BE', 'AT', 'CH', 'SE', 'NO', 'DK', 'FI', 'PL', 'PT', 'GR'];
        return $this->isFromCountry($europeanCountries);
    }

    /**
     * Check if from Asia (simplified).
     */
    public function isFromAsia(): bool
    {
        $asianCountries = ['JP', 'CN', 'IN', 'KR', 'SG', 'MY', 'TH', 'ID', 'PH', 'VN'];
        return $this->isFromCountry($asianCountries);
    }

    /**
     * Check if coordinates are within a bounding box.
     */
    public function isWithinBounds(float $minLat, float $maxLat, float $minLon, float $maxLon): bool
    {
        if (!$this->hasCoordinates()) {
            return false;
        }

        return $this->latitude >= $minLat
            && $this->latitude <= $maxLat
            && $this->longitude >= $minLon
            && $this->longitude <= $maxLon;
    }

    /**
     * Calculate distance to coordinates (Haversine formula).
     * Returns distance in kilometers.
     */
    public function distanceTo(float $latitude, float $longitude): ?float
    {
        if (!$this->hasCoordinates()) {
            return null;
        }

        $earthRadiusKm = 6371;

        $dLat = deg2rad($latitude - $this->latitude);
        $dLon = deg2rad($longitude - $this->longitude);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadiusKm * $c;

        return round($distance, 2);
    }

    /**
     * Get region based on coordinates.
     */
    public function getRegion(): ?string
    {
        if (!$this->hasCoordinates()) {
            return null;
        }

        if ($this->isFromEurope()) {
            return 'Europe';
        }

        if ($this->isFromAsia()) {
            return 'Asia';
        }

        if ($this->isFromUS()) {
            return 'North America';
        }

        if ($this->isFromCountry(['CA', 'MX'])) {
            return 'North America';
        }

        if ($this->isFromCountry(['BR', 'AR', 'CL', 'CO', 'PE'])) {
            return 'South America';
        }

        if ($this->isFromCountry(['AU', 'NZ'])) {
            return 'Oceania';
        }

        if ($this->isFromCountry(['ZA', 'NG', 'KE', 'EG'])) {
            return 'Africa';
        }

        return 'Other';
    }
}

