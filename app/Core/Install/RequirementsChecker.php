<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * Reports what this installation environment actually provides.
 *
 * Written because every important decision here depends on facts about the host that nobody has
 * verified yet (`O-2`, `O-3`, `O-20`): PHP version, extensions, writable paths, database
 * reachability, upload limits, outgoing HTTP, cron hints. Guessing these is what turns a small
 * deployment into a rewrite.
 *
 * The report never contains credentials: connection results are reduced to booleans and, at most,
 * the server version string.
 */
final class RequirementsChecker
{
    public function __construct(
        private readonly string $appRoot,
        private readonly ?\App\Core\Config $config = null,
    ) {
    }

    /** @return list<array{name: string, ok: bool, detail: string, required: bool}> */
    public function run(): array
    {
        $checks = [];

        $phpVersion = PHP_VERSION;
        $checks[] = [
            'name' => 'نسخه PHP',
            'ok' => version_compare($phpVersion, '8.1.0', '>='),
            'detail' => $phpVersion . (version_compare($phpVersion, '8.1.0', '>=') ? '' : ' — حداقل مورد نیاز: 8.1'),
            'required' => true,
        ];

        foreach (['pdo', 'mbstring', 'json', 'openssl', 'fileinfo', 'session', 'hash', 'filter'] as $extension) {
            $checks[] = [
                'name' => 'افزونه ' . $extension,
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? 'موجود' : 'غایب — برای اجرای برنامه لازم است',
                'required' => true,
            ];
        }

        foreach (['curl' => 'ارتباط با زرین‌پال و کاوه‌نگار', 'gd' => 'پردازش تصویر (اختیاری)', 'zip' => 'فایل‌های فشرده (اختیاری)', 'intl' => 'توابع بین‌المللی (اختیاری)'] as $extension => $why) {
            $loaded = extension_loaded($extension);
            $checks[] = [
                'name' => 'افزونه ' . $extension,
                'ok' => $loaded,
                'detail' => $loaded ? 'موجود' : 'غایب — ' . $why,
                'required' => false,
            ];
        }

        $driver = $this->config?->string('database.driver', 'sqlite') ?? 'sqlite';
        $requiredDriver = $driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
        $checks[] = [
            'name' => 'درایور پایگاه‌داده (' . $driver . ')',
            'ok' => extension_loaded($requiredDriver),
            'detail' => extension_loaded($requiredDriver)
                ? 'موجود'
                : sprintf('غایب — افزونه %s برای درایور %s لازم است', $requiredDriver, $driver),
            'required' => true,
        ];

        $storagePath = $this->config?->path('storage.path', 'storage') ?? $this->appRoot . '/storage';
        $storage = $this->directoryState($storagePath);
        $checks[] = [
            'name' => 'پوشه storage قابل نوشتن',
            'ok' => $storage['ok'],
            'detail' => $storage['detail'],
            'required' => true,
        ];

        foreach (['logs', 'cache', 'uploads', 'tmp', 'backups'] as $sub) {
            $path = $storagePath . '/' . $sub;
            $state = $this->directoryState($path);
            $checks[] = [
                'name' => 'پوشه storage/' . $sub,
                'ok' => $state['ok'],
                'detail' => $state['detail'],
                'required' => $sub === 'logs',
            ];
        }

        $checks[] = [
            'name' => 'فایل پیکربندی',
            'ok' => $this->config !== null,
            'detail' => $this->config !== null
                ? 'خوانده شد (driver=' . $driver . ')'
                : 'وجود ندارد — برای نصب: config/config.example.php را به config/config.php کپی کنید یا bin/install.php را اجرا کنید',
            'required' => true,
        ];

        $network = $this->canReachNetwork();
        $checks[] = [
            'name' => 'دسترسی خروجی HTTP',
            'ok' => $network,
            'detail' => $network
                ? 'برقرار است (برای زرین‌پال و کاوه‌نگار لازم است)'
                : 'برقرار نشد — اگر پرداخت و پیامک باید کار کنند، این مورد باید روی هاست بررسی شود',
            'required' => false,
        ];

        $checks[] = [
            'name' => 'حجم مجاز آپلود',
            'ok' => $this->uploadLimitBytes() >= 4 * 1024 * 1024,
            'detail' => sprintf(
                'upload_max_filesize=%s ، post_max_size=%s',
                (string) ini_get('upload_max_filesize'),
                (string) ini_get('post_max_size'),
            ),
            'required' => false,
        ];

        // The SAPI and cron hints are reported as facts, not as a pass/fail check: a check that
        // cannot fail is not a check (see the QA rule).
        $checks[] = [
            'name' => 'محیط اجرا',
            'ok' => true,
            'detail' => 'SAPI=' . PHP_SAPI . ' ، احتمال اجرای cron وجود دارد: ' . ($this->hasCronHint() ? 'بله' : 'نامشخص (فایل bin/cron.php هنوز ساخته نشده)'),
            'required' => false,
        ];

        if ($this->config !== null) {
            $checks[] = $this->databaseCheck();
        }

        return $checks;
    }

    /** @return array{name: string, ok: bool, detail: string, required: bool} */
    private function databaseCheck(): array
    {
        if ($this->config === null) {
            return ['name' => 'اتصال پایگاه‌داده', 'ok' => false, 'detail' => 'پیکربندی موجود نیست', 'required' => true];
        }

        try {
            $connection = \App\Core\Database\Connection::fromConfig($this->config, $this->appRoot);
            $version = $connection->isSqlite()
                ? 'SQLite ' . (string) $connection->scalar('SELECT sqlite_version()')
                : (string) $connection->scalar('SELECT VERSION()');

            return [
                'name' => 'اتصال پایگاه‌داده',
                'ok' => true,
                'detail' => 'برقرار شد — ' . $version,
                'required' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'اتصال پایگاه‌داده',
                'ok' => false,
                'detail' => 'برقرار نشد: ' . $e->getMessage(),
                'required' => true,
            ];
        }
    }

    /**
     * Reports a directory's state WITHOUT creating anything.
     *
     * A check that creates the directory it is checking cannot fail for the reason it exists, and it
     * also hides the difference between "the host is ready" and "the check just made it ready".
     * Creating the directories is the installer's job (see the database rule on side effects).
     *
     * @return array{ok: bool, detail: string}
     */
    private function directoryState(string $path): array
    {
        if (!is_dir($path)) {
            return ['ok' => false, 'detail' => 'وجود ندارد: ' . $path . ' — bin/install.php آن را می‌سازد'];
        }
        if (!is_writable($path)) {
            return ['ok' => false, 'detail' => 'قابل نوشتن نیست: ' . $path . ' — دسترسی (permission) این پوشه را اصلاح کنید'];
        }

        return ['ok' => true, 'detail' => 'قابل نوشتن: ' . $path];
    }

    private function canReachNetwork(): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $handle = curl_init('https://www.zarinpal.com/');
        if ($handle === false) {
            return false;
        }
        curl_setopt_array($handle, [
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $code > 0;
    }

    private function uploadLimitBytes(): int
    {
        $value = (string) ini_get('upload_max_filesize');
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function hasCronHint(): bool
    {
        return is_dir($this->appRoot . '/bin') && is_file($this->appRoot . '/bin/cron.php');
    }

    public function hasFailures(): bool
    {
        foreach ($this->run() as $check) {
            if ($check['required'] && !$check['ok']) {
                return true;
            }
        }

        return false;
    }
}
