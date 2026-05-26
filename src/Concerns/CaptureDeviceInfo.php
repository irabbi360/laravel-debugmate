<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

/**
 * CaptureDeviceInfo - Trait for capturing device and browser information
 *
 * Extracts browser, OS, device type from user agent.
 */
trait CaptureDeviceInfo
{
    /**
     * Capture device information from user agent.
     */
    public function captureDeviceInfo(?string $userAgent = null): array
    {
        $ua = $userAgent ?? (function_exists('request') ? request()->userAgent() : '');

        return [
            'user_agent' => $ua,
            'device_type' => $this->detectDeviceType($ua),
            'browser' => $this->detectBrowser($ua),
            'browser_version' => $this->detectBrowserVersion($ua),
            'os' => $this->detectOS($ua),
            'os_version' => $this->detectOSVersion($ua),
            'is_mobile' => $this->isMobile($ua),
            'is_tablet' => $this->isTablet($ua),
            'is_bot' => $this->isBot($ua),
        ];
    }

    /**
     * Detect device type from user agent.
     */
    protected function detectDeviceType(string $ua): string
    {
        if ($this->isTablet($ua)) return 'tablet';
        if ($this->isMobile($ua)) return 'mobile';
        return 'desktop';
    }

    /**
     * Detect browser from user agent.
     */
    protected function detectBrowser(string $ua): ?string
    {
        if (preg_match('/Chrome|Chromium|CriOS/', $ua)) return 'Chrome';
        if (preg_match('/Firefox/', $ua)) return 'Firefox';
        if (preg_match('/Safari/', $ua) && !preg_match('/Chrome/', $ua)) return 'Safari';
        if (preg_match('/Edg/', $ua)) return 'Edge';
        if (preg_match('/Opera|OPR/', $ua)) return 'Opera';
        if (preg_match('/MSIE|Trident/', $ua)) return 'IE';
        return null;
    }

    /**
     * Detect browser version.
     */
    protected function detectBrowserVersion(string $ua): ?string
    {
        if (preg_match('/Chrome\/(\d+)/', $ua, $m)) return $m[1];
        if (preg_match('/Firefox\/(\d+)/', $ua, $m)) return $m[1];
        if (preg_match('/Version\/(\d+).*Safari/', $ua, $m)) return $m[1];
        if (preg_match('/Edg\/(\d+)/', $ua, $m)) return $m[1];
        if (preg_match('/OPR\/(\d+)/', $ua, $m)) return $m[1];
        return null;
    }

    /**
     * Detect operating system.
     */
    protected function detectOS(string $ua): ?string
    {
        if (preg_match('/Windows/', $ua)) return 'Windows';
        if (preg_match('/Macintosh|Mac OS X/', $ua)) return 'macOS';
        if (preg_match('/Linux/', $ua)) return 'Linux';
        if (preg_match('/iPhone|iPad|iPod/', $ua)) return 'iOS';
        if (preg_match('/Android/', $ua)) return 'Android';
        return null;
    }

    /**
     * Detect OS version.
     */
    protected function detectOSVersion(string $ua): ?string
    {
        if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) return $m[1];
        if (preg_match('/Mac OS X ([\d_]+)/', $ua, $m)) return str_replace('_', '.', $m[1]);
        if (preg_match('/Android ([\d.]+)/', $ua, $m)) return $m[1];
        if (preg_match('/OS ([\d_]+)/', $ua, $m)) return str_replace('_', '.', $m[1]);
        return null;
    }

    /**
     * Check if user agent is mobile.
     */
    protected function isMobile(string $ua): bool
    {
        return preg_match('/Mobile|Android|iPhone|iPod|webOS/', $ua) > 0;
    }

    /**
     * Check if user agent is tablet.
     */
    protected function isTablet(string $ua): bool
    {
        return preg_match('/Tablet|iPad/', $ua) > 0;
    }

    /**
     * Check if user agent is a bot/crawler.
     */
    protected function isBot(string $ua): bool
    {
        $bots = ['bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python', 'java'];
        foreach ($bots as $bot) {
            if (stripos($ua, $bot) !== false) {
                return true;
            }
        }
        return false;
    }
}

