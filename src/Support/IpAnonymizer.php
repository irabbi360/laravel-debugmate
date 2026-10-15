<?php

namespace Irabbi360\LaravelDebugMate\Support;

/**
 * Truncates IP addresses so they no longer identify a single host.
 *
 * IPv4 keeps the first 24 bits (a.b.c.0); IPv6 keeps the first 48 bits.
 */
class IpAnonymizer
{
    public static function anonymize(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return $ip;
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        if (strlen($packed) === 4) {
            $masked = substr($packed, 0, 3)."\0";
        } else {
            $masked = substr($packed, 0, 6).str_repeat("\0", 10);
        }

        return inet_ntop($masked) ?: $ip;
    }
}
