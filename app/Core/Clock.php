<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Single source of "now", so that time-dependent behaviour is testable and so that
 * timezone handling is decided in exactly one place.
 *
 * Storage uses UTC; the Iranian timezone (Asia/Tehran) is a presentation concern
 * (see the localization rule). Tests may freeze the clock.
 */
final class Clock
{
    private static ?int $frozenAt = null;

    public static function init(string $timezone = 'UTC'): void
    {
        date_default_timezone_set($timezone);
    }

    public static function now(): int
    {
        return self::$frozenAt ?? time();
    }

    public static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', self::now());
    }

    public static function utcDateTime(): string
    {
        return gmdate('Y-m-d H:i:s', self::now());
    }

    /** @internal test helper */
    public static function freeze(int $timestamp): void
    {
        self::$frozenAt = $timestamp;
    }

    /** @internal test helper */
    public static function unfreeze(): void
    {
        self::$frozenAt = null;
    }
}
