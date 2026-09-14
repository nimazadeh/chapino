<?php

declare(strict_types=1);

namespace App\Core\Install;

use App\Core\Config;
use App\Core\Database\Connection;

/**
 * Reports what this environment actually provides.
 *
 * Written because every important decision here depends on facts about the host that nobody has
 * verified yet (`O-2`, `O-3`, `O-20`): PHP version, extensions, writable paths, database
 * reachability, upload limits, outgoing HTTP, cron evidence. Guessing these is what turns a small
 * deployment into a rewrite.
 *
 * Three kinds of entry, and the difference is not cosmetic:
 *
 *   `required` - the product cannot run correctly without it; a failure here stops an install.
 *   `optional` - a feature degrades without it (payments, SMS, large uploads); a warning, not a failure.
 *   `fact`     - an observed value with no pass/fail meaning (PHP's memory limit, free disk space, the
 *                drivers actually compiled in). Reporting a fact as "PASS" would train the reader to
 *                ignore the report - a check that cannot fail is not a check (QA rule).
 *
 * The report never contains credentials: connection results are reduced to booleans and, at most, the
 * server version string.
 */
final class RequirementsChecker
{
    /** Ratified in `O-3`: the code must run on PHP 8.1 and must not use anything newer. */
    public const MINIMUM_PHP = '8.1.0';

    /** Extensions the product uses. `O-3` names mbstring, pdo, json, curl, openssl, zip. */
    private const REQUIRED_EXTENSIONS = [
        'pdo' => 'لایه‌ی پایگاه‌داده',
        'mbstring' => 'متن فارسی و برش امن رشته‌ها',
        'json' => 'پاسخ‌های API و ذخیره‌ی سند طراحی',
        'curl' => 'زرین‌پال و کاوه‌نگار (C-10، C-11)',
        'openssl' => 'اتصال امن و امضای درخواست‌ها',
        'zip' => 'بسته‌بندی دارایی‌های سفارش (O-3)',
        'session' => 'ورود کاربر',
        'hash' => 'هش توکن‌ها و کلیدها',
        'filter' => 'اعتبارسنجی ورودی‌ها',
        'fileinfo' => 'تشخیص نوع فایل آپلودی بر اساس محتوا، نه پسوند',
    ];

    private const OPTIONAL_EXTENSIONS = [
        'gd' => 'پردازش تصویر سمت سرور (اختیاری؛ پردازش اصلی طرح در مرورگر است - C-6)',
        'intl' => 'توابع بین‌المللی و قالب‌بندی پیشرفته‌ی تاریخ',
    ];

    public function __construct(
        private readonly string $appRoot,
        private readonly ?Config $config = null,
    ) {
    }

    /**
     * @return list<array{name: string, kind: 'required'|'optional'|'fact', ok: bool, detail: string}>
     */
    public function run(): array
    {
        $checks = [];

        $phpVersion = PHP_VERSION;
        $checks[] = $this->entry(
            'نسخه PHP',
            'required',
            version_compare($phpVersion, self::MINIMUM_PHP, '>='),
            $phpVersion . (version_compare($phpVersion, self::MINIMUM_PHP, '>=') ? '' : ' — حداقل مورد نیاز: ' . self::MINIMUM_PHP),
        );

        foreach (self::REQUIRED_EXTENSIONS as $extension => $why) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->entry(
                'افزونه ' . $extension,
                'required',
                $loaded,
                $loaded ? 'موجود — ' . $why : 'غایب — ' . $why . ' (O-3)',
            );
        }

        foreach (self::OPTIONAL_EXTENSIONS as $extension => $why) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->entry('افزونه ' . $extension, 'optional', $loaded, $loaded ? 'موجود — ' . $why : 'غایب — ' . $why);
        }

        $checks[] = $this->driverCheck();

        $checks[] = $this->entry(
            'فایل پیکربندی',
            'required',
            $this->config !== null,
            $this->config !== null
                ? 'خوانده شد'
                : 'وجود ندارد — برای نصب: config/config.example.php را به config/config.php کپی کنید یا bin/install.php را اجرا کنید',
        );

        $storagePath = $this->absoluteStoragePath();
        $checks[] = $this->writableCheck('پوشه storage (نوشتن واقعی)', $storagePath, true);
        $checks[] = $this->writableCheck('پوشه storage/logs', $storagePath . '/logs', true);
        $checks[] = $this->writableCheck('پوشه‌ی ذخیره‌ی نشست‌ها', $this->sessionPath(), true);

        $checks[] = $this->entry(
            'دسترسی خروجی HTTP',
            'optional',
            $this->canReachNetwork(),
            $this->networkDetail(),
        );

        $uploadLimit = $this->iniBytes('upload_max_filesize');
        $postLimit = $this->iniBytes('post_max_size');
        // 4 MB is not a preference: a t-shirt design at 300 DPI (O-14) is a multi-megabyte upload, and
        // a host that refuses it silently makes the studio look broken.
        $checks[] = $this->entry(
            'حجم مجاز آپلود',
            'optional',
            $uploadLimit >= 4 * 1024 * 1024 && $postLimit >= 4 * 1024 * 1024,
            sprintf(
                'upload_max_filesize=%s ، post_max_size=%s%s',
                (string) ini_get('upload_max_filesize'),
                (string) ini_get('post_max_size'),
                ($uploadLimit >= 4 * 1024 * 1024 && $postLimit >= 4 * 1024 * 1024) ? '' : ' — برای آپلود طرح ۳۰۰DPI کم است؛ از پنل هاست افزایش دهید',
            ),
        );

        if ($this->config !== null) {
            $checks[] = $this->databaseCheck();
        }

        // --- facts: printed, never judged -------------------------------------------------------

        $checks[] = $this->entry('محیط اجرا', 'fact', true, 'SAPI=' . PHP_SAPI . ' ، سرور=' . $this->serverSoftware(), true);

        $checks[] = $this->entry(
            'درایورهای PDO موجود',
            'fact',
            true,
            $this->availableDrivers() === [] ? 'هیچ' : implode(', ', $this->availableDrivers()),
            true,
        );

        $checks[] = $this->entry(
            'محدودیت‌های اجرا',
            'fact',
            true,
            sprintf(
                'memory_limit=%s ، max_execution_time=%s ، allow_url_fopen=%s',
                (string) ini_get('memory_limit'),
                (string) ini_get('max_execution_time'),
                ini_get('allow_url_fopen') ? 'روشن' : 'خاموش',
            ),
            true,
        );

        $checks[] = $this->entry('فضای دیسک', 'fact', true, $this->diskSpace(), true);

        $checks[] = $this->entry(
            'زبان و زمان',
            'fact',
            true,
            sprintf(
                'default_charset=%s ، mbstring.internal_encoding=%s ، date.timezone=%s',
                (string) ini_get('default_charset'),
                (string) ini_get('mbstring.internal_encoding'),
                (string) ini_get('date.timezone'),
            ),
            true,
        );

        $checks[] = $this->cronCheck();

        return $checks;
    }

    /**
     * @return array{required_failures: int, optional_warnings: int, facts: int, ready: bool}
     */
    public function summary(): array
    {
        $required = 0;
        $optional = 0;
        $facts = 0;

        foreach ($this->run() as $check) {
            if ($check['kind'] === 'fact') {
                $facts++;
            } elseif (!$check['ok']) {
                $check['kind'] === 'required' ? $required++ : $optional++;
            }
        }

        return [
            'required_failures' => $required,
            'optional_warnings' => $optional,
            'facts' => $facts,
            'ready' => $required === 0,
        ];
    }

    /**
     * The facts, as name => detail, for a report that has to be recorded verbatim (`O-20`).
     *
     * @return array<string, string>
     */
    public function facts(): array
    {
        $facts = [];
        foreach ($this->run() as $check) {
            if ($check['kind'] === 'fact') {
                $facts[$check['name']] = $check['detail'];
            }
        }

        return $facts;
    }

    public function hasFailures(): bool
    {
        foreach ($this->run() as $check) {
            if ($check['kind'] === 'required' && !$check['ok']) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------------------------

    /** @return array{name: string, kind: 'required'|'optional'|'fact', ok: bool, detail: string} */
    private function entry(string $name, string $kind, bool $ok, string $detail, bool $informational = false): array
    {
        if ($informational && $kind !== 'fact') {
            throw new \LogicException('فقط ورودی‌های fact می‌توانند اطلاعاتی باشند.');
        }

        return ['name' => $name, 'kind' => $kind, 'ok' => $ok, 'detail' => $detail];
    }

    /** @return array{name: string, kind: 'required', ok: bool, detail: string} */
    private function driverCheck(): array
    {
        $driver = $this->driver();
        $extension = $driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
        $loaded = extension_loaded($extension);

        return $this->entry(
            'درایور پایگاه‌داده (' . $driver . ')',
            'required',
            $loaded,
            $loaded
                ? 'موجود'
                : sprintf('غایب — افزونه %s برای درایور %s لازم است', $extension, $driver),
        );
    }

    private function driver(): string
    {
        return strtolower($this->config?->string('database.driver', 'sqlite') ?? 'sqlite');
    }

    /** @return array{name: string, kind: 'required', ok: bool, detail: string} */
    private function databaseCheck(): array
    {
        if ($this->config === null) {
            return $this->entry('اتصال پایگاه‌داده', 'required', false, 'پیکربندی موجود نیست');
        }

        try {
            $connection = Connection::fromConfig($this->config, $this->appRoot);
            $version = $connection->isSqlite()
                ? 'SQLite ' . (string) $connection->scalar('SELECT sqlite_version()')
                : (string) $connection->scalar('SELECT VERSION()');

            $pending = $this->pendingMigrations($connection);

            return $this->entry(
                'اتصال پایگاه‌داده',
                'required',
                true,
                'برقرار شد — ' . $version . ($pending === null ? '' : ' ، مهاجرت در انتظار: ' . $pending),
            );
        } catch (\Throwable $e) {
            return $this->entry('اتصال پایگاه‌داده', 'required', false, 'برقرار نشد: ' . $e->getMessage());
        }
    }

    /**
     * How many migrations have not been applied, or null when that cannot be determined.
     *
     * Reading this here means the report answers "is this installation ready?" and not just "can it
     * connect": a database that connects but has no tables is the most common state after an upload
     * that skipped a step.
     */
    private function pendingMigrations(Connection $connection): ?int
    {
        try {
            if (!$connection->tableExists(\App\Core\Database\Migrator::TABLE)) {
                return count((new \App\Core\Database\Migrator(
                    $connection,
                    new \App\Core\Database\Schema($connection),
                    $this->appRoot . '/database/migrations',
                ))->available());
            }

            return count((new \App\Core\Database\Migrator(
                $connection,
                new \App\Core\Database\Schema($connection),
                $this->appRoot . '/database/migrations',
            ))->status()['pending']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{name: string, kind: 'required', ok: bool, detail: string} */
    private function writableCheck(string $name, string $path, bool $required): array
    {
        $exists = is_dir($path);
        if (!$exists) {
            return $this->entry(
                $name,
                'required',
                false,
                'وجود ندارد: ' . $path . ' — bin/install.php آن را می‌سازد',
            );
        }

        // is_writable() reports the permission bits, and on shared hosting those bits routinely
        // disagree with reality (NFS, ACLs, an open_basedir restriction, a full disk quota). The only
        // trustworthy answer comes from writing a byte and removing it again - and the probe file is
        // removed on every path out of this method.
        $probe = $path . '/.requirements-' . bin2hex(random_bytes(4)) . '.tmp';
        $written = @file_put_contents($probe, 'x');
        if ($written === false) {
            return $this->entry($name, 'required', false, 'نوشتن ممکن نشد (تست نوشتن واقعی): ' . $path);
        }

        $removed = @unlink($probe);
        if (!$removed) {
            return $this->entry(
                $name,
                'required',
                false,
                'فایل آزمایشی ساخته شد ولی پاک نشد: ' . $probe . ' — پس از نصب، این فایل را دستی پاک کنید',
            );
        }

        return $this->entry($name, 'required', $written === 1, 'قابل نوشتن: ' . $path);
    }

    private function absoluteStoragePath(): string
    {
        $configured = $this->config?->path('storage.path', 'storage') ?? $this->appRoot . '/storage';
        if (str_starts_with($configured, '/')) {
            return $configured;
        }

        return $this->appRoot . '/' . ltrim($configured, '/');
    }

    /**
     * The directory PHP will actually write session files into: the configured one when the
     * installation sets it, otherwise what PHP is configured with, otherwise the system temp
     * directory. Guessing this wrong is how "login does not work on this host" happens.
     */
    private function sessionPath(): string
    {
        $configured = (string) ($this->config?->string('security.session_save_path', '') ?? '');
        if ($configured !== '') {
            return str_starts_with($configured, '/')
                ? $configured
                : $this->appRoot . '/' . ltrim($configured, '/');
        }

        $ini = trim((string) ini_get('session.save_path'));
        // `session.save_path` can carry a depth prefix ("2;/var/lib/php/sessions") or be empty.
        if (str_contains($ini, ';')) {
            $ini = substr($ini, (int) strpos($ini, ';') + 1);
        }

        return $ini !== '' ? $ini : sys_get_temp_dir();
    }

    private function serverSoftware(): string
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? '';

        return is_string($software) && $software !== '' ? $software : 'نامشخص در خط فرمان';
    }

    /** @return list<string> */
    private function availableDrivers(): array
    {
        try {
            return array_values(array_filter(
                \PDO::getAvailableDrivers(),
                static fn (string $driver): bool => in_array($driver, ['mysql', 'sqlite'], true),
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    private function diskSpace(): string
    {
        $path = $this->absoluteStoragePath();
        if (function_exists('disk_free_space')) {
            $free = @disk_free_space(is_dir($path) ? $path : $this->appRoot);
            if (is_float($free) && $free > 0) {
                return sprintf('%.1f گیگابایت آزاد در %s', $free / 1024 / 1024 / 1024, $path);
            }
        }

        return 'اندازه‌گیری نشد (disk_free_space در دسترس نیست یا مسیر خوانده نشد)';
    }

    /**
     * Whether cron appears to be running.
     *
     * PHP cannot ask the operating system about the schedule, so the honest answer comes from
     * evidence: `bin/cron.php` writes `cron.last_run_at` every time it runs (a heartbeat), and this
     * method reads it back. "No heartbeat yet" is a legitimate state for a fresh installation, which
     * is why this is a fact and not a failure.
     *
     * @return array{name: string, kind: 'fact', ok: bool, detail: string}
     */
    private function cronCheck(): array
    {
        $scriptExists = is_file($this->appRoot . '/bin/cron.php');
        $heartbeat = null;

        if ($this->config !== null) {
            try {
                $settings = new \App\Core\Settings(Connection::fromConfig($this->config, $this->appRoot));
                if ($settings->has('cron.last_run_at')) {
                    $heartbeat = $settings->get('cron.last_run_at') . ' (کارهای برداشته‌شده: '
                        . ($settings->get('cron.last_run_jobs') ?? '?') . ')';
                }
            } catch (\Throwable) {
                $heartbeat = null;
            }
        }

        $detail = $scriptExists
            ? 'فایل bin/cron.php موجود است. '
            : 'فایل bin/cron.php پیدا نشد (نصب ناقص است). ';

        $detail .= $heartbeat !== null
            ? 'آخرین اجرا: ' . $heartbeat
            : 'هنوز هیچ اجرایی ثبت نشده است — اگر کران تنظیم شده باشد و اجرا نشود، این خط همین‌طور خالی می‌ماند. تشخیص خودکار زمان‌بندی از داخل PHP ممکن نیست؛ این خط شاهد است، نه تنظیم.';

        return $this->entry('وضعیت کران (شاهد اجرا)', 'fact', $scriptExists, $detail, true);
    }

    private function networkDetail(): string
    {
        if (!function_exists('curl_init')) {
            return 'افزونه curl نصب نیست، پس بررسی نشد — پرداخت و پیامک کار نخواهند کرد';
        }

        return $this->canReachNetwork()
            ? 'برقرار است (برای زرین‌پال و کاوه‌نگار لازم است)'
            : 'برقرار نشد — اگر پرداخت و پیامک باید کار کنند، خروجی HTTP روی هاست بررسی شود';
    }

    /**
     * A HEAD request to the payment gateway.
     *
     * Deliberately not a "ping": what matters is whether THIS host can reach the provider over HTTPS
     * with certificate verification, which is exactly what the payment flow will do.
     */
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

    /** Reads an ini shorthand size ("2M", "512K", "1G", "2048") as bytes. */
    private function iniBytes(string $name): int
    {
        $value = trim((string) ini_get($name));
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
