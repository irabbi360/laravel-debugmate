<?php

namespace Irabbi360\LaravelDebugMate\Concerns;

/**
 * CaptureSanitization - Trait for sanitizing sensitive data
 *
 * Removes or masks sensitive information like passwords, tokens, API keys, etc.
 */
trait CaptureSanitization
{
    /**
     * Sensitive keys that should be sanitized.
     */
    protected array $sensitiveKeys = [
        'password', 'token', 'api_key', 'api_secret', 'secret', 'authorization',
        'cookie', 'session', 'credit_card', 'cvv', 'ssn', 'auth',
        'x-api-token', 'x-csrf-token', 'x-app-token',
    ];

    /**
     * Sanitize array data - remove sensitive values.
     */
    public function sanitizeData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $sanitized[$key] = '***SANITIZED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } elseif (is_string($value) && $this->appearsToBeCredential($value)) {
                $sanitized[$key] = '***SANITIZED***';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize query for sensitive data.
     */
    public function sanitizeQuery(string $query): string
    {
        $query = preg_replace('/password\s*=\s*[\'"][^\'"]*[\'"]/', 'password = ***', $query);
        $query = preg_replace('/token\s*=\s*[\'"][^\'"]*[\'"]/', 'token = ***', $query);
        $query = preg_replace('/api_key\s*=\s*[\'"][^\'"]*[\'"]/', 'api_key = ***', $query);
        $query = preg_replace('/api_secret\s*=\s*[\'"][^\'"]*[\'"]/', 'api_secret = ***', $query);
        return $query;
    }

    /**
     * Sanitize exception context.
     */
    public function sanitizeContext(array $context): array
    {
        return $this->sanitizeData($context);
    }

    /**
     * Sanitize for serialization - handle non-serializable objects.
     */
    public function sanitizeForSerialization(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if ($value === null) {
                $sanitized[$key] = null;
            } elseif (is_scalar($value)) {
                $sanitized[$key] = $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeForSerialization($value);
            } elseif ($value instanceof \JsonSerializable) {
                $sanitized[$key] = $value->jsonSerialize();
            } elseif ($value instanceof \Throwable) {
                $sanitized[$key] = [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            } elseif (is_object($value)) {
                // Skip non-serializable objects
                continue;
            }
        }

        return $sanitized;
    }

    /**
     * Check if key is sensitive.
     */
    protected function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach ($this->sensitiveKeys as $sensitiveKey) {
            if (strpos($key, strtolower($sensitiveKey)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if string appears to be a credential (e.g., bearer token, hash).
     */
    protected function appearsToBeCredential(string $value): bool
    {
        // Check for JWT-like strings (xxxxx.xxxxx.xxxxx)
        if (preg_match('/^[A-Za-z0-9\-._~+\/]+=*\.[A-Za-z0-9\-._~+\/]+=*\.[A-Za-z0-9\-._~+\/]+=*$/', $value)) {
            return true;
        }
        // Check for API key-like strings (long hex or alphanumeric)
        if (strlen($value) > 40 && preg_match('/^[A-Fa-f0-9]{32,}$/', $value)) {
            return true;
        }
        return false;
    }

    /**
     * Add custom sensitive keys to be sanitized.
     */
    public function addSensitiveKeys(array $keys): void
    {
        $this->sensitiveKeys = array_merge($this->sensitiveKeys, $keys);
    }
}

