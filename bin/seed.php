<?php

declare(strict_types=1);

/**
 * Default-settings seeder.
 *
 * Usage
 *   php bin/seed.php                 apply every missing default
 *   php bin/seed.php --status        show what this installation HOLDS and what is missing (writes nothing)
 *   php bin/seed.php --json          machine-readable output, for pasting into a record
 *
 * Safety: **writing is create-only**. An existing value is never replaced, so running this on a live
 * installation cannot undo a decision the operator made in the panel. That is why it needs no
 * `--force`, and why the output distinguishes "added" from "kept" instead of reporting a total.
 *
 * `--status` prints the STORED value, not the default from the seed file. That distinction is the
 * reason this script exists in a second form at all: an operator asking "what is my print resolution
 * set to?" must not be shown the value the product would have used on a fresh install.
 *
 * Exit code 0 means the requested state was reached; 1 means an error; 2 means bad usage.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("seed.php must be run from the command line.\n");
}

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app', 'App\\');
App\Core\Clock::init();

use App\Core\Config;
use App\Core\Database\Connection;
use App\Core\Database\SeedException;
use App\Core\Database\Seeder;
use App\Core\Settings;

$mode = 'apply';
$asJson = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--status') {
        $mode = 'status';
    } elseif ($argument === '--json') {
        $asJson = true;
    } else {
        fwrite(STDERR, "گزینه ناشناخته: {$argument}\n");
        exit(2);
    }
}

try {
    $config = Config::load($root);
    $connection = Connection::fromConfig($config, $root);
    $settings = new Settings($connection);
    $seeder = new Seeder($settings, $root . '/database/seeds');
} catch (Throwable $e) {
    fwrite(STDERR, "\nخطا: " . $e->getMessage() . "\n");
    fwrite(STDERR, "اگر نصب انجام نشده است، ابتدا bin/install.php را اجرا کنید یا فایل config/config.php را بسازید.\n");
    exit(1);
}

if (!$connection->tableExists(Settings::TABLE)) {
    // A clear, actionable message instead of a driver-level "no such table": on a fresh upload this
    // is the normal state, not a defect, and the fix is one command the operator already has.
    fwrite(STDERR, "\nجدول «" . Settings::TABLE . "» وجود ندارد، پس هنوز چیزی برای مقدارگذاری نیست.\n");
    fwrite(STDERR, "اول مهاجرت‌ها را اجرا کنید: php bin/migrate.php\n");
    exit(1);
}

try {
    $defaults = $seeder->defaults();
    $defaultValues = array_map(static fn (array $definition): string => $definition['value'], $defaults);
    $ownerInput = array_keys(array_filter($defaults, static fn (array $d): bool => $d['owner_input']));

    if ($mode === 'status') {
        $comparison = $settings->compareWithDefaults($defaultValues);
        $values = [];
        foreach (array_keys($defaults) as $key) {
            $values[$key] = $settings->get($key);
        }

        if ($asJson) {
            echo json_encode(
                [
                    'driver' => $connection->driver(),
                    'seeds' => $seeder->available(),
                    'total' => count($defaults),
                    'values' => $values,
                    'defaults' => $defaultValues,
                    'missing' => $comparison['missing'],
                    'changed' => $comparison['changed'],
                    'owner_input' => $ownerInput,
                ],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) . "\n";
            exit(0);
        }

        printf("پایگاه‌داده: %s\n", $connection->driver());
        printf(
            "تنظیمات: %d کلید ، %d غایب ، %d با پیش‌فرض متفاوت (تصمیم خودتان)\n\n",
            count($defaults),
            count($comparison['missing']),
            count($comparison['changed']),
        );

        foreach ($defaults as $key => $definition) {
            $stored = $values[$key];

            if ($stored === null) {
                printf(
                    "غایب   %s — پیش‌فرض: %s\n",
                    $key,
                    $definition['value'] === '' || $definition['owner_input'] ? 'خالی' : $definition['value'],
                );

                continue;
            }

            $marker = isset($comparison['changed'][$key])
                ? '   ← با پیش‌فرض متفاوت (پیش‌فرض: ' . ($definition['value'] === '' ? 'خالی' : $definition['value']) . ')'
                : '';

            printf(
                "موجود  %s = %s%s\n",
                $key,
                $stored === '' ? '(خالی — در انتظار تصمیم شما)' : $stored,
                $marker,
            );
        }

        echo "\nبرای مقدارگذاری موارد غایب: php bin/seed.php\n";
        exit(0);
    }

    $result = $seeder->run();
    $comparison = $settings->compareWithDefaults($defaultValues);

    if ($asJson) {
        echo json_encode(
            [
                'driver' => $connection->driver(),
                'added' => $result['added'],
                'kept' => $result['kept'],
                'changed' => $comparison['changed'],
                'owner_input' => $result['owner_input'],
            ],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";
        exit(0);
    }

    printf("پایگاه‌داده: %s\n", $connection->driver());
    printf(
        "تنظیمات پیش‌فرض: %d افزوده شد ، %d از قبل موجود بود (دست‌نخورده ماند)\n",
        count($result['added']),
        count($result['kept']),
    );

    if ($result['added'] !== []) {
        echo "\nافزوده شد:\n";
        foreach ($result['added'] as $key) {
            printf(
                "   + %s = %s\n",
                $key,
                $defaults[$key]['value'] === '' ? 'خالی (در انتظار تصمیم شما)' : $defaults[$key]['value'],
            );
        }
    }

    if ($comparison['changed'] !== []) {
        echo "\nمقدارهایی که خودتان تغییر داده‌اید (دست‌نخورده ماند):\n";
        foreach ($comparison['changed'] as $key => $stored) {
            printf("   • %s = %s\n", $key, $stored);
        }
    }

    $emptyOwnerInput = array_values(array_filter(
        $result['owner_input'],
        static fn (string $key): bool => ($settings->get($key) ?? '') === '',
    ));

    if ($emptyOwnerInput !== []) {
        echo "\nاین کلیدها عمداً خالی مانده‌اند (تصمیم شماست، هیچ عددی حدس زده نشد):\n";
        foreach ($emptyOwnerInput as $key) {
            printf("   • %s — %s\n", $key, $defaults[$key]['note']);
        }
    }

    echo "\nوضعیت کامل با php bin/seed.php --status یا خروجی ماشینی با --json قابل دیدن است.\n";
    exit(0);
} catch (SeedException $e) {
    fwrite(STDERR, "\nخطا در فایل seed «{$e->seed}»:\n   " . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "\nخطا: " . $e->getMessage() . "\n");
    exit(1);
}
