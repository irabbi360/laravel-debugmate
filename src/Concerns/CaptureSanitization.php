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
        'auth', 'pass', 'pwd', 'pin', 'otp', 'sid', 'key', 'hash', 'code_verifier', 'one_time_password',
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
        $query = (string) preg_replace(
            '/\b(password|passwd|token|api_key|api_secret|secret)\s*=\s*([\'"])[^\'"]*\2/i',
            '$1 = ***',
            $query
        );

        // Values interpolated into raw SQL (DB::raw, whereRaw("email = '$x'"))
        // are as sensitive as bindings: mask single-quoted string literals
        // ('' and \' escapes included) under the same setting.
        if ((bool) config('debugmate.redact_query_bindings', true)) {
            $query = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "'***'", $query);
        }

        return $query;
    }

    /**
     * Mask secrets in a URL, keeping it usable for debugging: token-like path
     * segments (reset tokens, webhook secrets, bot tokens) and sensitive
     * query-string parameters are replaced; everything else is preserved.
     */
    public function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $fragment = '';
        if (str_contains($url, '#')) {
            [$url, $fragment] = explode('#', $url, 2);
            $fragment = '#'.$fragment;
        }

        $query = null;
        if (str_contains($url, '?')) {
            [$url, $query] = explode('?', $url, 2);
        }

        // Keep "scheme://host:port" intact; only the path is inspected.
        $prefix = '';
        if (preg_match('#^([a-z][a-z0-9+.-]*://[^/]*)(.*)$#i', $url, $m)) {
            [, $prefix, $url] = $m;
        }

        return $prefix.$this->sanitizePath($url)
            .($query !== null ? '?'.$this->sanitizeQueryString($query) : '')
            .$fragment;
    }

    /**
     * Replace token-like path segments with "***".
     */
    public function sanitizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return $path;
        }

        $segments = explode('/', $path);

        foreach ($segments as $i => $segment) {
            if ($segment !== '' && $this->appearsToBeSecretSegment(rawurldecode($segment))) {
                $segments[$i] = '***';
            }
        }

        return implode('/', $segments);
    }

    /**
     * Heuristic for secrets embedded in URL paths. Tuned to keep ordinary
     * identifiers: slugs (lowercase words), numeric ids, UUIDs and ULIDs.
     */
    protected function appearsToBeSecretSegment(string $segment): bool
    {
        // Telegram bot token: bot123456:ABC-DEF...
        if (preg_match('/^(bot)?\d{5,}:[A-Za-z0-9_-]{20,}$/', $segment)) {
            return true;
        }

        if ($this->appearsToBeCredential($segment)) {
            return true;
        }

        if (strlen($segment) < 20 || ! preg_match('/^[A-Za-z0-9_\-.~]+$/', $segment)) {
            return false;
        }

        // Random tokens mix upper case, lower case and digits; slugs, UUIDs
        // and ULIDs don't.
        return preg_match('/[A-Z]/', $segment)
            && preg_match('/[a-z]/', $segment)
            && preg_match('/\d/', $segment);
    }

    /**
     * URL for the current request with secrets removed.
     *
     * Route parameters with sensitive names ({token}, {hash}, {signature}, ...)
     * are replaced by their placeholder, e.g. /reset-password/{token}, then the
     * generic path and query-string masking is applied.
     */
    public function sanitizeRequestUrl(\Illuminate\Http\Request $request, bool $withQuery = true): string
    {
        $url = $withQuery ? $request->fullUrl() : $request->url();

        try {
            $route = $request->route();
            $parameters = is_object($route) && method_exists($route, 'parameters') ? $route->parameters() : [];
        } catch (\Throwable) {
            $parameters = [];
        }

        foreach ($parameters as $name => $value) {
            if (! is_string($name) || ! (is_string($value) || is_int($value)) || ! $this->isSensitiveKey($name)) {
                continue;
            }

            $value = (string) $value;
            if ($value === '') {
                continue;
            }

            foreach (array_unique([rawurlencode($value), $value]) as $candidate) {
                $url = (string) preg_replace(
                    '#/'.preg_quote($candidate, '#').'(?=/|\?|$)#',
                    '/{'.$name.'}',
                    $url
                );
            }
        }

        return (string) $this->sanitizeUrl($url);
    }

    /**
     * Scrub free text (exception and log messages): URLs are sanitized,
     * "secret=value" / "secret: value" pairs, Bearer tokens and
     * credential-shaped strings are masked.
     */
    public function sanitizeMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        // URLs embedded in text (e.g. Guzzle: "`GET https://api.x/v1?api_key=..`")
        $message = (string) preg_replace_callback(
            '#\b[a-z][a-z0-9+.-]*://[^\s`\'"<>]+#i',
            fn (array $m) => (string) $this->sanitizeUrl($m[0]),
            $message
        );

        // key=value / key: value / "key":"value" where the key is sensitive
        $message = (string) preg_replace(
            '/(["\']?\b[\w-]*(?:password|passwd|secret|token|api[_-]?key|authorization|credential|signature)["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&)}\]]+)/i',
            '$1***',
            $message
        );

        // Bearer / Basic credentials
        $message = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/=]+/i', '$1 ***', $message);

        // Credential-shaped tokens anywhere in the text
        return (string) preg_replace(
            [
                '/\$(2[abxy]|argon2i|argon2id)\$[^\s\'"]+/',
                '/\b(sk|pk|rk)_(live|test)_[A-Za-z0-9]{10,}\b/',
                '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/',
                '/\bxox[abpr]-[A-Za-z0-9-]{10,}\b/',
                '/\bAKIA[0-9A-Z]{16}\b/',
                '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/',
            ],
            '***',
            $message
        );
    }

    /**
     * Database driver messages repeat the offending values
     * (MySQL: "Duplicate entry 'a@b.c' for key ...",
     *  PostgreSQL: "Key (email)=(a@b.c) already exists").
     * Quoted literals and Key (...)=(...) values are masked.
     */
    public function sanitizeDatabaseMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $message = (string) preg_replace('/(Key \([^)]*\)=\()(.*?)(\))/', '$1***$3', $message);
        $message = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'***'", $message);
        $message = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"(?=\s+(?:for|in|at)\b)/', '"***"', $message);

        return $this->sanitizeMessage($message);
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
            $leafName = (string) preg_replace('/^.*\[([^\]]*)\]$/', '$1', $decodedName);

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
     * Client IP as it may be reported (anonymized unless debugmate.anonymize_ips is off).
     */
    public function sanitizeIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '' || ! (bool) config('debugmate.anonymize_ips', true)) {
            return $ip;
        }

        return \Irabbi360\LaravelDebugMate\Support\IpAnonymizer::anonymize($ip);
    }

    /**
     * Anonymize client IPs carried in proxy headers (X-Forwarded-For,
     * Forwarded, CF-Connecting-IP, ...). Header names are matched lower-case.
     */
    public function sanitizeIpHeaders(array $headers): array
    {
        if (! (bool) config('debugmate.anonymize_ips', true)) {
            return $headers;
        }

        $ipHeaders = ['x-forwarded-for', 'x-real-ip', 'x-client-ip', 'x-cluster-client-ip',
            'cf-connecting-ip', 'true-client-ip', 'fastly-client-ip', 'x-original-forwarded-for'];

        foreach ($headers as $name => $value) {
            if (! is_string($value)) {
                continue;
            }

            $lower = strtolower((string) $name);

            if (in_array($lower, $ipHeaders, true)) {
                $headers[$name] = implode(', ', array_map(
                    fn ($ip) => (string) $this->sanitizeIp(trim($ip)),
                    explode(',', $value)
                ));
            } elseif ($lower === 'forwarded') {
                // RFC 7239: for=192.0.2.60;proto=http, for="[2001:db8::1]:4711"
                $headers[$name] = (string) preg_replace_callback(
                    '/\b(for|by)=("?)\[?([0-9a-fA-F:.]+?)\]?(:\d+)?\2(?=[;,\s]|$)/i',
                    fn ($m) => $m[1].'='.$m[2].$this->sanitizeIp($m[3]).$m[2],
                    $value
                );
            }
        }

        return $headers;
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
                    'message' => $this->sanitizeMessage($value->getMessage()),
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
