<?php

namespace Irabbi360\LaravelDebugMate\Services;

class BotDetector
{
    /**
     * List of known bot/crawler user agents.
     */
    protected array $botPatterns = [
        // Search engines
        'googlebot',
        'bingbot',
        'slurp',
        'duckduckbot',
        'baiduspider',
        'yandexbot',
        'sogou',

        // Social media
        'facebookexternalhit',
        'twitterbot',
        'linkedinbot',
        'pinterest',
        'whatsapp',
        'telegram',

        // Monitoring & SEO tools
        'semrushbot',
        'ahrefs',
        'mj12bot',
        'dotbot',
        'majestic',
        'screaming frog',
        'seobotscout',

        // Analytics & monitoring
        'uptimerobot',
        'pingdom',
        'monitis',
        'libwww-perl',
        'python-requests',
        'curl',
        'wget',
        'java',

        // Other crawlers
        'archive.org_bot',
        'applebot',
        'googleadbot',
        'msofficebot',
        'slack',

        // Additional patterns
        'bot',
        'crawler',
        'spider',
        'scraper',
    ];

    /**
     * Whitelist of legitimate bots to always allow.
     */
    protected array $whitelist = [
        'googlebot',
        'bingbot',
        'applebot',
    ];

    public function __construct(protected array $config = [])
    {
    }

    /**
     * Detect if current request is from a bot.
     */
    public function isBot(string $userAgent = null): bool
    {
        if (!($this->config['bot_filtering'] ?? true)) {
            return false;
        }

        $userAgent = $userAgent ?? request()->userAgent() ?? '';
        $userAgent = strtolower($userAgent);

        // Check whitelist first (return false immediately)
        foreach ($this->whitelist as $pattern) {
            if (strpos($userAgent, strtolower($pattern)) !== false) {
                return false;
            }
        }

        // Check bot patterns
        foreach ($this->botPatterns as $pattern) {
            if (strpos($userAgent, strtolower($pattern)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a custom bot pattern.
     */
    public function addPattern(string $pattern): self
    {
        $this->botPatterns[] = strtolower($pattern);

        return $this;
    }

    /**
     * Add multiple bot patterns.
     */
    public function addPatterns(array $patterns): self
    {
        foreach ($patterns as $pattern) {
            $this->addPattern($pattern);
        }

        return $this;
    }

    /**
     * Add to whitelist.
     */
    public function addToWhitelist(string $pattern): self
    {
        $this->whitelist[] = strtolower($pattern);

        return $this;
    }

    /**
     * Get all bot patterns.
     */
    public function getPatterns(): array
    {
        return $this->botPatterns;
    }

    /**
     * Get whitelist.
     */
    public function getWhitelist(): array
    {
        return $this->whitelist;
    }
}

