<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * DeviceDataDTO - Data Transfer Object for Device Information
 *
 * Encapsulates device and browser detection data parsed from user agent.
 * Provides helper methods for device categorization.
 */
class DeviceDataDTO implements Arrayable, JsonSerializable
{
    /**
     * Create a new DeviceDataDTO instance.
     */
    public function __construct(
        public readonly string $device_type,      // desktop, mobile, tablet
        public readonly ?string $device_name = null,
        public readonly ?string $browser = null,
        public readonly ?string $browser_version = null,
        public readonly ?string $os = null,
        public readonly ?string $os_version = null,
    ) {}

    /**
     * Create DeviceDataDTO from array.
     */
    public static function from(array $data): self
    {
        return new self(
            device_type: $data['device_type'] ?? 'desktop',
            device_name: $data['device_name'] ?? null,
            browser: $data['browser'] ?? null,
            browser_version: $data['browser_version'] ?? null,
            os: $data['os'] ?? null,
            os_version: $data['os_version'] ?? null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'device_type' => $this->device_type,
            'device_name' => $this->device_name,
            'browser' => $this->browser,
            'browser_version' => $this->browser_version,
            'os' => $this->os,
            'os_version' => $this->os_version,
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
     * Check if device is mobile.
     */
    public function isMobile(): bool
    {
        return $this->device_type === 'mobile';
    }

    /**
     * Check if device is tablet.
     */
    public function isTablet(): bool
    {
        return $this->device_type === 'tablet';
    }

    /**
     * Check if device is desktop.
     */
    public function isDesktop(): bool
    {
        return $this->device_type === 'desktop';
    }

    /**
     * Check if browser is Chrome.
     */
    public function isChrome(): bool
    {
        return $this->browser === 'Chrome';
    }

    /**
     * Check if browser is Firefox.
     */
    public function isFirefox(): bool
    {
        return $this->browser === 'Firefox';
    }

    /**
     * Check if browser is Safari.
     */
    public function isSafari(): bool
    {
        return $this->browser === 'Safari';
    }

    /**
     * Check if OS is Windows.
     */
    public function isWindows(): bool
    {
        return $this->os === 'Windows';
    }

    /**
     * Check if OS is macOS.
     */
    public function isMacOS(): bool
    {
        return $this->os === 'macOS';
    }

    /**
     * Check if OS is Linux.
     */
    public function isLinux(): bool
    {
        return $this->os === 'Linux';
    }

    /**
     * Check if OS is iOS.
     */
    public function isIOS(): bool
    {
        return $this->os === 'iOS';
    }

    /**
     * Check if OS is Android.
     */
    public function isAndroid(): bool
    {
        return $this->os === 'Android';
    }

    /**
     * Get full device string.
     */
    public function getFullDeviceString(): string
    {
        $parts = [];

        if ($this->browser) {
            $parts[] = $this->browser . ($this->browser_version ? " {$this->browser_version}" : '');
        }

        if ($this->os) {
            $parts[] = $this->os . ($this->os_version ? " {$this->os_version}" : '');
        }

        if ($this->device_name) {
            $parts[] = $this->device_name;
        }

        return implode(' on ', $parts) ?: 'Unknown Device';
    }

    /**
     * Get device category for grouping.
     */
    public function getCategory(): string
    {
        return match($this->device_type) {
            'mobile' => 'Mobile Devices',
            'tablet' => 'Tablets',
            'desktop' => 'Desktops',
            default => 'Other',
        };
    }

    /**
     * Get browser family (ignore version for grouping).
     */
    public function getBrowserFamily(): string
    {
        return $this->browser ?? 'Unknown';
    }

    /**
     * Get OS family (ignore version for grouping).
     */
    public function getOSFamily(): string
    {
        return $this->os ?? 'Unknown';
    }
}

