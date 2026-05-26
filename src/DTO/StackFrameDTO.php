<?php

namespace Irabbi360\LaravelDebugMate\DTO;

use Irabbi360\LaravelDebugMate\Data\BaseDTO;

/**
 * StackFrameDTO - Represents a single stack trace frame
 */
class StackFrameDTO extends BaseDTO
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $function,
        public readonly ?string $class = null,
        public readonly ?string $type = null,
        public readonly array $args = [],
    ) {}

    public static function from(array $data): static
    {
        return new static(
            file: $data['file'] ?? 'unknown',
            line: $data['line'] ?? 0,
            function: $data['function'] ?? 'unknown',
            class: $data['class'] ?? null,
            type: $data['type'] ?? null,
            args: $data['args'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'function' => $this->function,
            'class' => $this->class,
            'type' => $this->type,
            'args' => $this->args,
        ];
    }

    public function getShortPath(): string
    {
        $parts = explode('/', $this->file);
        return end($parts);
    }

    public function getContext(): string
    {
        if ($this->class && $this->type) {
            return "{$this->class}{$this->type}{$this->function}";
        }
        return $this->function;
    }
}


