<?php

namespace Irabbi360\LaravelDebugMate\Tracing;

class SpanEvent
{
    protected string $name;
    protected array $attributes;
    protected float $timestamp;

    public function __construct(string $name, array $attributes = [], float $timestamp = 0.0)
    {
        $this->name = $name;
        $this->attributes = $attributes;
        $this->timestamp = $timestamp;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'timestamp' => $this->timestamp,
            'attributes' => $this->attributes,
        ];
    }
}

