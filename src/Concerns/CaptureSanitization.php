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
     * Sensitive key fragments. A key is sensitive when it CONTAINS any of these.
     */
    protected array $sensitiveKeys = [
        'password', 'passwd', 'passphrase', 'token', 'api_key', 'apikey', 'api-key',
        'access_key', 'private_key', 'secret', 'authorization', 'cookie', 'session',
        'credit_card', 'creditcard', 'card_number', 'cardnumber', 'cvv', 'cvc',
        'ssn', 'iban', 'php-auth', 'signature', 'credential', 'x-api-token',
        'x-csrf-token', 'x-xsrf-token', 'x-app-token', 'x-debugmate-key',
    ];

    /**
     * Short sensitive keys that only match EXACTLY (substring matching would
     * catch innocent keys such as "passport" or "spinner").
     */
    protected array $sensitiveExactKeys = [
        'auth', 'pass', 'pwd', 'pin', 'otp', 'sid', 'key', 'code_verifier', 'one_time_password',
    ];

    /**
     * Sanitize array data - remove sensitive values.
     */
    public function sanitizeData(array $data, int $depth = 0): array
    {
        if ($depth > 20) {
            return ['***MAX_DEPTH***'];
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $sanitized[$key] = '***SANITIZED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value, $depth + 1);
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
        return (string) preg_replace(
            '/\b(password|passwd|token|api_key|api_secret|secret)\s*=\s*([\'"])[^\'"]*\2/i',
            '$1 = ***',
            $query
        );
    }

    /**
     * Mask sensitive query-string parameters in a URL, keeping the URL usable
     * for debugging (path and non-sensitive parameters are preserved).
     */
    public function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '' || ! str_contains($url, '?')) {
            return $url;
        }

        [$base, $query] = explode('?', $url, 2);
        $fragment = '';

        if (str_contains($query, '#')) {
            [$query, $fragment] = explode('#', $query, 2);
            $fragment = '#'.$fragment;
        }

        return $base.'?'.$this->sanitizeQueryString($query).$fragment;
    }

    /**
     * Mask sensitive parameters in a raw query string (without the leading "?").
     */
    public function sanitizeQueryString(?string $query): ?string
    {
        if ($query === null || $query === '') {
            return $query;
        }

        $pairs = explode('&', $query);

        foreach ($pairs as $i => $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $decodedName = urldecode($name);
            // Strip array brackets: "user[password]" -> check "password" too
            $leafName = preg_replace('/^.*\[([^\]]*)\]$/', '$1', $decodedName);

            $sensitive = $this->isSensitiveKey($decodedName)
                || ($leafName !== '' && $this->isSensitiveKey($leafName))
                || ($value !== null && $this->appearsToBeCredential(urldecode($value)));

            if ($sensitive && $value !== null) {
                $pairs[$i] = $name.'=***';
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Redact query bindings. Numbers, booleans and nulls are kept (they are
     * rarely sensitive and are useful for debugging); strings are replaced
     * because they routinely carry emails, hashes and tokens.
     */
    public function sanitizeBindings(array $bindings): array
    {
        if (! (bool) config('debugmate.redact_query_bindings', true)) {
            return $bindings;
        }

        return array_map(function ($binding) {
            if ($binding === null || is_bool($binding) || is_int($binding) || is_float($binding)) {
                return $binding;
            }

            if ($binding instanceof \DateTimeInterface) {
                return $binding->format('Y-m-d H:i:s');
            }

            return '***';
        }, array_values($bindings));
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
    public function sanitizeForSerialization(array $context, int $depth = 0): array
    {
        if ($depth > 20) {
            return ['***MAX_DEPTH***'];
        }

        $sanitized = [];

        foreach ($context as $key => $value) {
            if ($value === null) {
                $sanitized[$key] = null;
            } elseif (is_scalar($value)) {
                $sanitized[$key] = $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeForSerialization($value, $depth + 1);
            } elseif ($value instanceof \Throwable) {
                $sanitized[$key] = [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            } elseif ($value instanceof \JsonSerializable) {
                try {
                    $serialized = $value->jsonSerialize();
                    $sanitized[$key] = is_array($serialized)
                        ? $this->sanitizeForSerialization($serialized, $depth + 1)
                        : (is_scalar($serialized) || $serialized === null ? $serialized : get_class($value));
                } catch (\Throwable) {
                    $sanitized[$key] = get_class($value);
                }
            } elseif ($value instanceof \Stringable) {
                $sanitized[$key] = (string) $value;
            }
            // Other objects, closures and resources are skipped.
        }

        return $sanitized;
    }

    /**
     * Check if key is sensitive.
     */
    protected function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->sensitiveExactKeys as $exactKey) {
            if ($key === strtolower($exactKey)) {
                return true;
            }
        }

        foreach ($this->sensitiveKeys as $sensitiveKey) {
            if (str_contains($key, strtolower($sensitiveKey))) {
                return true;
            }
        }

        foreach ((array) config('debugmate.sensitive_keys', []) as $customKey) {
            if (is_string($customKey) && $customKey !== '' && str_contains($key, strtolower($customKey))) {
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
        // Bearer / Basic authorization values
        if (preg_match('/^(Bearer|Basic)\s+\S+/i', $value)) {
            return true;
        }
        // Password hashes (bcrypt, argon2)
        if (preg_match('/^\$(2[abxy]|argon2i|argon2id)\$/', $value)) {
            return true;
        }
        // JWT-like strings (xxxxx.xxxxx.xxxxx)
        if (strlen($value) > 20 && preg_match('/^[A-Za-z0-9\-_]+=*\.[A-Za-z0-9\-_]+=*\.[A-Za-z0-9\-_+\/]+=*$/', $value)) {
            return true;
        }
        // API key-like strings (long hex)
        if (strlen($value) >= 32 && preg_match('/^[A-Fa-f0-9]+$/', $value)) {
            return true;
        }
        // Common vendor key prefixes (Stripe, GitHub, Slack, AWS)
        if (preg_match('/^(sk|pk|rk)_(live|test)_[A-Za-z0-9]{10,}$|^gh[pousr]_[A-Za-z0-9]{20,}$|^xox[abpr]-[A-Za-z0-9-]{10,}$|^AKIA[0-9A-Z]{16}$/', $value)) {
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
