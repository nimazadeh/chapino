<?php

declare(strict_types=1);

/**
 * Installer, driven from the command line.
 *
 * Usage
 *   php bin/install.php --driver=sqlite
 *   php bin/install.php --driver=mysql --host=127.0.0.1 --port=3306 --name=chapino \
 *        --user=root --password=secret
 *   php bin/install.php --driver=mysql ... --url=https://example.ir --force
 *
 * What it does, in order: prepares storage, verifies the database connection, writes
 * config/config.php, then runs every migration. Nothing is written before the connection is proven
 * to work - a half-written installation is worse than none.
 *
 * This script never runs from the web (PHP_SAPI check): an installer reachable over HTTP is a
 * takeover vector. A web installer for hosts without shell access is built on the same Installer
 * class in the deployment slice, behind a one-time token and with a self-disable step.
 *
 * Exit code 0 means the installation is ready.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("install.php must be run from the command line.\n");
}

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app', 'App\\');
App\Core\Clock::init();

use App\Core\Config;
use App\Core\Install\Installer;
use App\Core\Install\RequirementsChecker;

$options = [
    'driver' => 'sqlite',
    'host' => 'localhost',
    'port' => '3306',
    'name' => '',
    'user' => '',
    'password' => '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'url' => '',
    'force' => false,
    'skip-requirements' => false,
];

foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        fwrite(STDERR, "ناشناخته: {$argument}\n");
        exit(2);
    }
    [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, 'true');
    if (!array_key_exists($key, $options)) {
        fwrite(STDERR, "گزینه ناشناخته: --{$key}\n");
        exit(2);
    }
    $options[$key] = $key === 'force' || $key === 'skip-requirements' ? true : $value;
}

try {
    $installer = new Installer($root);

    echo "۱) آماده‌سازی پوشه‌های نوشتنی\n";
    $created = $installer->prepareStorage(['path' => 'storage']);
    echo $created === []
        ? "   همه پوشه‌ها از قبل وجود داشتند\n"
        : '   ساخته شد: ' . implode(', ', array_map(static fn (string $p): string => str_replace($root . '/', '', $p), $created)) . "\n";

    // One mapping, shared with the web installer: the CLI and the browser must never disagree about
    // what a valid database configuration is.
    $databaseSettings = Installer::databaseSettings($options);

    echo "۲) بررسی اتصال پایگاه‌داده\n";
    $serverVersion = $installer->checkConnection($databaseSettings);
    echo "   اتصال برقرار شد — {$serverVersion}\n";

    echo "۳) نوشتن فایل پیکربندی\n";
    $configuration = Installer::configuration($options + ['storage_path' => 'storage']);
    $path = $installer->writeConfiguration($configuration, (bool) $options['force']);
    echo '   نوشته شد: ' . str_replace($root . '/', '', $path) . "\n";

    echo "۴) اجرای مهاجرت‌ها\n";
    $applied = $installer->migrate($databaseSettings);
    $seeded = $installer->seed($databaseSettings);
    echo $applied === []
        ? "   هیچ مهاجرت در انتظاری وجود نداشت (نصب از قبل کامل است)\n"
        : '   اجرا شد: ' . implode(', ', $applied) . "\n";

    echo "۵) مقدارگذاری تنظیمات پیش‌فرض\n";
    printf(
        "   %d تنظیم افزوده شد، %d از قبل موجود بود (دست‌نخورده ماند)\n",
        count($seeded['added']),
        count($seeded['kept']),
    );
    if ($seeded['owner_input'] !== []) {
        echo "   این کلیدها عمداً خالی مانده‌اند و منتظر تصمیم شماست:\n";
        foreach ($seeded['owner_input'] as $key) {
            echo "      • {$key}\n";
        }
    }

    if (!$options['skip-requirements']) {
        echo "۶) بررسی نیازمندی‌ها\n";
        $config = Config::load($root);
        $checker = new RequirementsChecker($root, $config);
        $failed = 0;
        foreach ($checker->run() as $check) {
            if ($check['kind'] === 'required' && !$check['ok']) {
                $failed++;
                printf("   [مورد نیاز] %s: %s\n", $check['name'], $check['detail']);
            }
        }
        echo $failed === 0
            ? "   همه نیازمندی‌های اجباری برقرار است\n"
            : "   {$failed} مورد نیاز برقرار نیست (بالا را ببینید)\n";
    }

    echo "\nنصب کامل شد. برای بررسی نهایی اجرا کنید:\n";
    echo "   php bin/smoke.php\n";
    echo "   php bin/migrate.php --status\n";
    echo "   php bin/seed.php --status\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nنصب با خطا متوقف شد:\n   " . $e->getMessage() . "\n");
    exit(1);
}
