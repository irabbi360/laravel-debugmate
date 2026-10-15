<?php

namespace Irabbi360\LaravelDebugMate\Services;

use Throwable;
use ReflectionClass;

class StackTraceParser
{
    /** @var array<string, array<int, string>|false> File contents read during the current parse. */
    protected array $fileCache = [];

    /**
     * Parse a throwable exception into detailed stack frames.
     */
    public function parseThrowable(Throwable $exception): array
    {
        $frames = [];
        $trace = $exception->getTrace();

        try {
            // Add the initial frame where the exception was thrown
            $frames[] = $this->createFrameFromException($exception);

            // Add frames from the stack trace
            foreach ($trace as $index => $frame) {
                $frames[] = $this->createFrameFromTrace($frame, $index);
            }
        } finally {
            // Don't keep file contents alive between reports.
            $this->fileCache = [];
        }

        return $frames;
    }

    /**
     * Create a frame from the exception throw point.
     */
    protected function createFrameFromException(Throwable $exception): array
    {
        $file = $exception->getFile();
        $line = $exception->getLine();

        return [
            'file' => $file,
            'relative_file' => $this->getRelativeFile($file),
            'line_number' => $line,
            'column_number' => null,
            'class' => null,
            'method' => null,
            // The throw point always gets a snippet (unless snippets are off).
            'code_snippet' => $this->snippetMode() === 'none' ? [] : $this->getCodeSnippet($file, $line),
            'application_frame' => $this->isApplicationFrame($file),
            'arguments' => [],
        ];
    }

    /**
     * Create a frame from a stack trace entry.
     */
    protected function createFrameFromTrace(array $frame, int $index): array
    {
        $file = $frame['file'] ?? null;
        $line = $frame['line'] ?? null;
        $class = $frame['class'] ?? null;
        $function = $frame['function'] ?? null;
        $type = $frame['type'] ?? '::';

        $method = null;
        if ($class && $function) {
            $method = $class . $type . $function;
        } elseif ($function) {
            $method = $function;
        }

        // Get arguments
        $arguments = $this->formatArguments($frame['args'] ?? []);

        return [
            'file' => $file,
            'relative_file' => $file ? $this->getRelativeFile($file) : null,
            'line_number' => $line,
            'column_number' => null,
            'class' => $class,
            'method' => $function,
            'code_snippet' => $file && $line && $this->shouldIncludeSnippet($file) ? $this->getCodeSnippet($file, $line) : [],
            'application_frame' => $file ? $this->isApplicationFrame($file) : false,
            'arguments' => $arguments,
        ];
    }

    /**
     * Get code snippet around a given line.
     *
     * IMPORTANT: Must use FILE_IGNORE_NEW_LINES only (NOT FILE_SKIP_EMPTY_LINES)
     * to preserve correct line numbering
     */
    protected function getCodeSnippet(string $file, int $line, ?int $context = null): array
    {
        $snippet = [];
        $context ??= $this->snippetLines();

        if ($context <= 0) {
            return $snippet;
        }

        try {
            // FILE_IGNORE_NEW_LINES only - do NOT use FILE_SKIP_EMPTY_LINES
            // Empty lines must be preserved to keep line numbers accurate
            if (! array_key_exists($file, $this->fileCache)) {
                $this->fileCache[$file] = is_file($file) && is_readable($file)
                    ? file($file, FILE_IGNORE_NEW_LINES)
                    : false;
            }

            $content = $this->fileCache[$file];

            if (!is_array($content)) {
                return $snippet;
            }

            $halfContext = (int)($context / 2);
            // Line numbers in file are 1-based, array is 0-based
            $startLine = max(0, $line - $halfContext - 1);
            $endLine = min(count($content) - 1, $line + $halfContext - 1);

            for ($i = $startLine; $i <= $endLine; $i++) {
                // Line number in file (1-based)
                $lineNumber = $i + 1;
                // Get line content (preserve empty lines as empty strings)
                $snippet[$lineNumber] = $content[$i] ?? '';
            }
        } catch (\Throwable) {
            // Silently ignore file read errors
        }

        return $snippet;
    }

    /**
     * debugmate.code_snippets: application (default), all or none.
     */
    protected function snippetMode(): string
    {
        $mode = function_exists('config') ? config('debugmate.code_snippets', 'application') : 'application';

        return in_array($mode, ['application', 'all', 'none'], true) ? $mode : 'application';
    }

    protected function snippetLines(): int
    {
        $lines = function_exists('config') ? (int) config('debugmate.code_snippet_lines', 30) : 30;

        return max(0, min($lines, 200));
    }

    protected function shouldIncludeSnippet(string $file): bool
    {
        return match ($this->snippetMode()) {
            'all' => true,
            'none' => false,
            default => $this->isApplicationFrame($file),
        };
    }

    /**
     * Get relative file path from project root.
     *
     * Converts:
     * /Users/frabbi/Herd/debugmate/app/Http/Controllers/ErrorController.php
     * to:
     * app/Http/Controllers/ErrorController.php
     */
    protected function getRelativeFile(string $file): string
    {
        // Normalize paths (convert backslashes to forward slashes)
        $file = str_replace('\\', '/', $file);
        $basePath = str_replace('\\', '/', base_path());

        // If file starts with base path, remove it
        if (strpos($file, $basePath) === 0) {
            // Remove base path and leading slash
            $relative = substr($file, strlen($basePath) + 1);
            return ltrim($relative, '/');
        }

        // For vendor files, show from vendor onwards
        if (strpos($file, '/vendor/') !== false) {
            $parts = explode('/vendor/', $file);
            $vendorPath = 'vendor/' . (end($parts) ?? $file);
            return ltrim($vendorPath, '/');
        }

        // For other paths, return as-is but ensure leading slash removed
        return ltrim($file, '/');
    }

    /**
     * Check if this is an application frame (not from vendor).
     */
    protected function isApplicationFrame(string $file): bool
    {
        $vendorPath = base_path('vendor');
        $node_modulesPath = base_path('node_modules');

        // Check if file is in vendor or node_modules
        if (strpos($file, $vendorPath) === 0) {
            return false;
        }

        if (strpos($file, $node_modulesPath) === 0) {
            return false;
        }

        return true;
    }

    /**
     * Format function arguments for display.
     */
    protected function formatArguments(array $args): array
    {
        $formatted = [];

        foreach ($args as $arg) {
            $formatted[] = [
                'name' => null,
                'value' => $this->formatValue($arg),
                'original_type' => $this->getType($arg),
                'passed_by_reference' => false,
                'is_variadic' => false,
                'truncated' => false,
            ];
        }

        return $formatted;
    }

    /**
     * Format a value for display.
     */
    protected function formatValue(mixed $value): mixed
    {
        // String arguments routinely carry secrets (Hash::check($plainPassword),
        // encrypt($token), ...). Only their type and length are reported.
        if (is_string($value)) {
            return 'string('.strlen($value).')';
        }

        if (is_array($value)) {
            return 'array (' . count($value) . ')';
        }

        if (is_object($value)) {
            return 'object(' . get_class($value) . ')';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return $value;
        }

        return (string)$value;
    }

    /**
     * Get the type of a value.
     */
    protected function getType(mixed $value): string
    {
        if (is_object($value)) {
            return get_class($value);
        }

        return gettype($value);
    }

    /**
     * Convert frames to JSON-serializable format.
     */
    public function toArray(array $frames): array
    {
        return array_map(function ($frame) {
            return [
                'file' => $frame['file'] ?? null,
                'relative_file' => $frame['relative_file'] ?? null,
                'line_number' => $frame['line_number'] ?? null,
                'column_number' => $frame['column_number'] ?? null,
                'class' => $frame['class'] ?? null,
                'method' => $frame['method'] ?? null,
                'code_snippet' => $frame['code_snippet'] ?? [],
                'application_frame' => $frame['application_frame'] ?? false,
                'arguments' => $frame['arguments'] ?? [],
            ];
        }, $frames);
    }
}

