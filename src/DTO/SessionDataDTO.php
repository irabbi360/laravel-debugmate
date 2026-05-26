<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * SessionDataDTO - Data Transfer Object for Session Information
 *
 * Encapsulates all session-related analytics data including
 * device information, geographic data, and visitor metrics.
 */
class SessionDataDTO implements Arrayable, JsonSerializable
{
    /**
     * Create a new SessionDataDTO instance.
     */
    public function __construct(
        public readonly string $session_id,
        public readonly string $project_key,
        public readonly string $user_agent,
        public readonly string $ip_address,
        public readonly ?string $referrer = null,
        public readonly ?int $user_id = null,
        // Browser info
        public readonly ?string $browser = null,
        public readonly ?string $browser_version = null,
        // OS info
        public readonly ?string $os = null,
        public readonly ?string $os_version = null,
        // Device info
        public readonly string $device_type = 'desktop',
        public readonly ?string $device_name = null,
        // Geo info
        public readonly ?string $country = null,
        public readonly ?string $country_code = null,
        public readonly ?string $city = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        // Metrics
        public readonly int $page_views = 1,
        public readonly int $bounce_count = 0,
        public readonly array $pages_visited = [],
        public readonly float $session_start_time = 0.0,
        public readonly ?string $user_fingerprint = null,
    ) {}

    /**
     * Create SessionDataDTO from array.
     */
    public static function from(array $data): self
    {
        return new self(
            session_id: $data['session_id'] ?? '',
            project_key: $data['project_key'] ?? '',
            user_agent: $data['user_agent'] ?? '',
            ip_address: $data['ip_address'] ?? '',
            referrer: $data['referrer'] ?? null,
            user_id: $data['user_id'] ?? null,
            browser: $data['browser'] ?? null,
            browser_version: $data['browser_version'] ?? null,
            os: $data['os'] ?? null,
            os_version: $data['os_version'] ?? null,
            device_type: $data['device_type'] ?? 'desktop',
            device_name: $data['device_name'] ?? null,
            country: $data['country'] ?? null,
            country_code: $data['country_code'] ?? null,
            city: $data['city'] ?? null,
            latitude: $data['latitude'] ?? null,
            longitude: $data['longitude'] ?? null,
            page_views: $data['page_views'] ?? 1,
            bounce_count: $data['bounce_count'] ?? 0,
            pages_visited: $data['pages_visited'] ?? [],
            session_start_time: $data['session_start_time'] ?? microtime(true),
            user_fingerprint: $data['user_fingerprint'] ?? null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->session_id,
            'project_key' => $this->project_key,
            'user_agent' => $this->user_agent,
            'ip_address' => $this->ip_address,
            'referrer' => $this->referrer,
            'user_id' => $this->user_id,
            'browser' => $this->browser,
            'browser_version' => $this->browser_version,
            'os' => $this->os,
            'os_version' => $this->os_version,
            'device_type' => $this->device_type,
            'device_name' => $this->device_name,
            'country' => $this->country,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'page_views' => $this->page_views,
            'bounce_count' => $this->bounce_count,
            'pages_visited' => $this->pages_visited,
            'session_start_time' => $this->session_start_time,
            'user_fingerprint' => $this->user_fingerprint,
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
     * Check if user is a bounce (single page visitor).
     */
    public function isBounce(): bool
    {
        return $this->page_views === 1 && $this->bounce_count > 0;
    }

    /**
     * Get total pages visited count.
     */
    public function getTotalPagesVisited(): int
    {
        return count($this->pages_visited);
    }

    /**
     * Check if this is a mobile device.
     */
    public function isMobile(): bool
    {
        return in_array($this->device_type, ['mobile', 'tablet']);
    }

    /**
     * Check if this is a desktop device.
     */
    public function isDesktop(): bool
    {
        return $this->device_type === 'desktop';
    }

    /**
     * Get full device description.
     */
    public function getDeviceDescription(): string
    {
        $parts = [];

        if ($this->browser) {
            $parts[] = $this->browser . ($this->browser_version ? " {$this->browser_version}" : '');
        }

        if ($this->os) {
            $parts[] = $this->os . ($this->os_version ? " {$this->os_version}" : '');
        }

        if ($this->device_type) {
            $parts[] = ucfirst($this->device_type);
        }

        return implode(' on ', $parts) ?: 'Unknown Device';
    }

    /**
     * Get full location description.
     */
    public function getLocationDescription(): string
    {
        $parts = [];

        if ($this->city) {
            $parts[] = $this->city;
        }

        if ($this->country) {
            $parts[] = $this->country;
        }

        if (empty($parts) && $this->country_code) {
            return $this->country_code;
        }

        return implode(', ', $parts) ?: 'Unknown Location';
    }
}

