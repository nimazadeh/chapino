<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Removes secrets and personal data from anything that is about to be logged.
 *
 * Logging must never become a security incident (see the security rule). This runs on
 * every log call, so a careless caller cannot leak a token by accident.
 */
final class Redactor
{
    private const SENSITIVE_KEYS = [
        'password', 'pass', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization',
        'auth', 'cookie', 'session', 'session_id', 'csrf', 'csrf_token', 'otp', 'code',
        'merchant_id', 'private_key', 'card', 'national_id', 'mobile', 'phone', 'email',
        'address', 'ip', 'api_key_sms',
    ];

    private const MASK = '[redacted]';

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public static function clean(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $out[$key] = self::MASK;
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::clean($value);
                continue;
            }
            $out[$key] = is_scalar($value) || $value === null ? $value : gettype($value);
        }

        return $out;
    }

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Masks a value for display, keeping the last characters for support conversations. */
    public static function maskValue(string $value, int $keep = 4): string
    {
        $length = strlen($value);
        if ($length <= $keep) {
            return self::MASK;
        }

        return self::MASK . substr($value, -$keep);
    }
}
