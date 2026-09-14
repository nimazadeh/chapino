<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal PSR-4 style autoloader.
 *
 * Deliberately hand-written: the product must install on shared hosting with no
 * dependency manager and no shell access (ADR-0002).
 */
final class Autoloader
{
    /** @var array<string, string> prefix => base directory */
    private static array $prefixes = [];

    public static function register(string $baseDir, string $prefix): void
    {
        $key = rtrim($prefix, '\\') . '\\';
        self::$prefixes[$key] = rtrim($baseDir, '/\\') . '/';

        // Idempotent: a bootstrap that is included more than once (CLI scripts, tests)
        // must not register the same loader twice.
        foreach (spl_autoload_functions() ?: [] as $registered) {
            if ($registered === [self::class, 'load']) {
                return;
            }
        }
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
            return;
        }
    }
}
