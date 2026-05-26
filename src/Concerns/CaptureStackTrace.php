<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

/**
 * CaptureStackTrace - Trait for capturing and parsing exception stack traces
 *
 * Converts exception information into structured frame data.
 */
trait CaptureStackTrace
{
    /**
     * Capture stack trace from throwable exception.
     */
    public function captureStackTrace(\Throwable $exception): array
    {
        $frames = [];
        foreach ($exception->getTrace() as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'args' => $this->sanitizeArgs($frame['args'] ?? []),
            ];
        }
        return $frames;
    }

    /**
     * Get exception as string representation.
     */
    public function exceptionToString(\Throwable $exception): string
    {
        return $exception->getTraceAsString();
    }

    /**
     * Extract exception metadata.
     */
    public function captureExceptionMetadata(\Throwable $exception): array
    {
        return [
            'type' => class_basename($exception),
            'full_type' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'previous' => $exception->getPrevious() ? [
                'type' => get_class($exception->getPrevious()),
                'message' => $exception->getPrevious()->getMessage(),
            ] : null,
        ];
    }

    /**
     * Sanitize function arguments for serialization.
     */
    protected function sanitizeArgs(array $args): array
    {
        return array_map(function ($arg) {
            if (is_object($arg)) {
                return 'Object(' . get_class($arg) . ')';
            } elseif (is_array($arg)) {
                return 'Array(' . count($arg) . ')';
            } elseif (is_resource($arg)) {
                return 'Resource(' . get_resource_type($arg) . ')';
            } else {
                return $arg;
            }
        }, $args);
    }

    /**
     * Generate fingerprint from exception for grouping.
     */
    public function generateErrorFingerprint(\Throwable $exception): string
    {
        $type = class_basename($exception);
        $file = basename($exception->getFile());
        $line = $exception->getLine();
        return md5("{$type}:{$file}:{$line}");
    }
}

