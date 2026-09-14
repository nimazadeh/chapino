<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuration access.
 *
 * Real configuration lives in config/config.php, which is NOT in the repository
 * (it holds secrets and host paths). config/config.example.php is the committed
 * template. Dot notation is supported: config('database.host').
 */
final class Config
{
    /**
     * @param array<string, mixed> $values
     * @param string $root the directory relative paths are resolved against
     */
    private function __construct(
        private array $values,
        private readonly string $root,
    ) {
    }

    /**
     * @param array<string, mixed>|null $overrides test seam: pass values directly
     */
    public static function load(string $root, ?array $overrides = null): self
    {
        if ($overrides !== null) {
            return new self(array_replace_recursive(self::defaults(), $overrides), $root);
        }

        // An explicit path wins: hosting panels sometimes keep configuration outside the
        // application directory, and tests need an isolated configuration.
        $configuredPath = getenv('CHAPINO_CONFIG');
        $path = ($configuredPath !== false && $configuredPath !== '')
            ? $configuredPath
            : $root . '/config/config.php';

        if (!is_file($path)) {
            throw new ConfigurationException(
                'فایل پیکربندی پیدا نشد. برای نصب، فایل config/config.example.php را به config/config.php کپی کنید.',
                'config_missing',
            );
        }

        /** @var mixed $values */
        $values = require $path;
        if (!is_array($values)) {
            throw new ConfigurationException(
                'فایل پیکربندی معتبر نیست. باید یک آرایه PHP برگرداند.',
                'config_invalid',
            );
        }

        return new self(array_replace_recursive(self::defaults(), $values), $root);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'app' => [
                'name' => 'chapino',
                'env' => 'production',
                'debug' => false,
                'url' => '',
                'timezone' => 'UTC',
            ],
            'storage' => [
                'path' => 'storage',
            ],
            'database' => [
                'driver' => 'sqlite',
                'sqlite_path' => 'storage/database.sqlite',
                'host' => 'localhost',
                'port' => 3306,
                'name' => '',
                'user' => '',
                'password' => '',
                'charset' => 'utf8mb4',
            ],
            'security' => [
                'session_name' => 'chapino_session',
                'session_idle_timeout' => 3600,
                'session_absolute_timeout' => 86400,
            ],
            'logging' => [
                'level' => 'info',
                'path' => 'storage/logs',
            ],
            'sms' => [
                'provider' => 'kavenegar',
                'api_key' => '',
                'sender' => '',
            ],
            'payment' => [
                'provider' => 'zarinpal',
                'merchant_id' => '',
                'sandbox' => true,
            ],
            'ai' => [
                // Owner decision C-12: no AI provider in the current scope. The seam
                // exists and is switched off here (ADR-0003).
                'enabled' => false,
                'provider' => 'none',
            ],
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->values;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function isDebug(): bool
    {
        return $this->bool('app.debug', false);
    }

    public function path(string $key, string $default = ''): string
    {
        $value = $this->string($key, $default);
        if ($value === '') {
            return '';
        }

        // Relative paths belong to the installation they are declared in, not to whatever
        // APP_ROOT happens to be: this is what keeps a second installation (a test copy, a
        // staging deployment) from writing into the first one's storage directory.
        return str_starts_with($value, '/') ? $value : $this->root . '/' . $value;
    }
}
