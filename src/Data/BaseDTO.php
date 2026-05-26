<?php

namespace Irabbi360\LaravelDebugMate\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * BaseDTO - Base class for all Data Transfer Objects
 *
 * Provides common DTO functionality for data validation, serialization, and transformation.
 */
abstract class BaseDTO implements Arrayable, JsonSerializable
{
    /**
     * Convert DTO to array representation.
     */
    abstract public function toArray(): array;

    /**
     * Convert DTO to JSON representation.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(int $flags = JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toArray(), $flags);
    }

    /**
     * Convert to array for database storage.
     * Override in child classes for custom database formatting.
     */
    public function toDatabase(): array
    {
        return $this->toArray();
    }

    /**
     * Create DTO from array.
     * Each subclass should implement this factory method.
     */
    abstract public static function from(array $data): static;

    /**
     * Merge another DTO into this one.
     */
    public function merge(BaseDTO $other): static
    {
        $merged = array_merge($this->toArray(), $other->toArray());
        return static::from($merged);
    }

    /**
     * Get a value from the DTO's array representation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->toArray()[$key] ?? $default;
    }

    /**
     * Check if key exists in DTO.
     */
    public function has(string $key): bool
    {
        return isset($this->toArray()[$key]);
    }

    /**
     * Filter DTO to only include specified keys.
     */
    public function only(array $keys): array
    {
        $data = $this->toArray();
        return array_filter($data, fn($key) => in_array($key, $keys), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Exclude specified keys from DTO.
     */
    public function except(array $keys): array
    {
        $data = $this->toArray();
        return array_filter($data, fn($key) => !in_array($key, $keys), ARRAY_FILTER_USE_KEY);
    }
}

