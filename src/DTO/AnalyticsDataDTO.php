<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Irabbi360\LaravelDebugMate\Data\BaseDTO;

/**
 * AnalyticsDataDTO - Data Transfer Object for Analytics Information
 */
class AnalyticsDataDTO extends BaseDTO
{
    public function __construct(
        public readonly string $session_id,
        public readonly string $type, // 'session', 'page_view', 'event'
        public readonly ?string $project_key = null,
        public readonly ?int $user_id = null,
        public readonly ?string $user_fingerprint = null,
        public readonly ?string $user_agent = null,
        public readonly ?string $ip_address = null,
        public readonly ?string $referrer = null,
        public readonly ?string $device_type = null,
        public readonly ?string $browser = null,
        public readonly ?string $os = null,
        public readonly ?string $country = null,
        public readonly ?string $country_code = null,
        public readonly ?string $city = null,
        public readonly ?string $page_url = null,
        public readonly ?string $page_title = null,
        public readonly float $page_views = 0,
        public readonly float $bounce_count = 0,
        public readonly float $session_duration_seconds = 0,
        public readonly ?float $load_time_ms = null,
        public readonly int $status_code = 200,
        public readonly ?string $event_name = null,
        public readonly array $event_data = [],
        public readonly ?string $event_category = null,
        public readonly array $context = [],
    ) {}

    public static function from(array $data): static
    {
        return new static(
            session_id: $data['session_id'] ?? \Illuminate\Support\Str::uuid()->toString(),
            type: $data['type'] ?? 'page_view',
            project_key: $data['project_key'] ?? null,
            user_id: $data['user_id'] ?? null,
            user_fingerprint: $data['user_fingerprint'] ?? null,
            user_agent: $data['user_agent'] ?? null,
            ip_address: $data['ip_address'] ?? null,
            referrer: $data['referrer'] ?? null,
            device_type: $data['device_type'] ?? null,
            browser: $data['browser'] ?? null,
            os: $data['os'] ?? null,
            country: $data['country'] ?? null,
            country_code: $data['country_code'] ?? null,
            city: $data['city'] ?? null,
            page_url: $data['page_url'] ?? null,
            page_title: $data['page_title'] ?? null,
            page_views: $data['page_views'] ?? 0,
            bounce_count: $data['bounce_count'] ?? 0,
            session_duration_seconds: $data['session_duration_seconds'] ?? 0,
            load_time_ms: $data['load_time_ms'] ?? null,
            status_code: $data['status_code'] ?? 200,
            event_name: $data['event_name'] ?? null,
            event_data: $data['event_data'] ?? [],
            event_category: $data['event_category'] ?? null,
            context: $data['context'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'session_id' => $this->session_id,
            'type' => $this->type,
            'project_key' => $this->project_key,
            'user_id' => $this->user_id,
            'user_fingerprint' => $this->user_fingerprint,
            'user_agent' => $this->user_agent,
            'ip_address' => $this->ip_address,
            'referrer' => $this->referrer,
            'device_type' => $this->device_type,
            'browser' => $this->browser,
            'os' => $this->os,
            'country' => $this->country,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'page_url' => $this->page_url,
            'page_title' => $this->page_title,
            'page_views' => $this->page_views,
            'bounce_count' => $this->bounce_count,
            'session_duration_seconds' => $this->session_duration_seconds,
            'load_time_ms' => $this->load_time_ms,
            'status_code' => $this->status_code,
            'event_name' => $this->event_name,
            'event_data' => $this->event_data,
            'event_category' => $this->event_category,
            'context' => $this->context,
        ];
    }

    public function isSession(): bool
    {
        return $this->type === 'session';
    }

    public function isPageView(): bool
    {
        return $this->type === 'page_view';
    }

    public function isEvent(): bool
    {
        return $this->type === 'event';
    }

    public function isBounce(): bool
    {
        return $this->bounce_count > 0;
    }

    public function getSessionDurationMinutes(): float
    {
        return round($this->session_duration_seconds / 60, 2);
    }

    public function getSummary(): string
    {
        return "{$this->type}: {$this->page_url} ({$this->session_duration_seconds}s)";
    }
}


