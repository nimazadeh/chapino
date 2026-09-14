<?php

declare(strict_types=1);

/**
 * Migration runner.
 *
 * Usage
 *   php bin/migrate.php --status            what is applied and what is pending
 *   php bin/migrate.php                     apply every pending migration
 *   php bin/migrate.php --down              roll back the most recent batch
 *   php bin/migrate.php --down=2            roll back the two most recent batches
 *   php bin/migrate.php --fresh --force     drop everything and re-migrate (local development ONLY)
 *
 * Safety: `--fresh` destroys data, so it refuses to run without `--force` (see the database rule,
 * which forbids casually destroying existing data).
 *
 * Exit code 0 means the requested state was reached.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php must be run from the command line.\n");
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
use App\Core\Database\MigrationException;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;

$arguments = array_slice($argv, 1);
$mode = 'up';
$batches = 1;
$force = false;

foreach ($arguments as $argument) {
    if ($argument === '--status') {
        $mode = 'status';
    } elseif ($argument === '--fresh') {
        $mode = 'fresh';
    } elseif ($argument === '--force') {
        $force = true;
    } elseif (str_starts_with($argument, '--down')) {
        $mode = 'down';
        $batches = str_contains($argument, '=') ? max(1, (int) substr($argument, 7)) : 1;
    } else {
        fwrite(STDERR, "گزینه ناشناخته: {$argument}\n");
        exit(2);
    }
}

try {
    $config = Config::load($root);
    $connection = Connection::fromConfig($config, $root);
    $schema = new Schema(
        $connection,
        $config->string('database.charset', 'utf8mb4'),
        $config->string('database.collation', 'utf8mb4_unicode_ci'),
    );
    $migrator = new Migrator($connection, $schema, $root . '/database/migrations');

    $status = $migrator->status();
    printf("پایگاه‌داده: %s\n", $connection->driver());
    printf("مهاجرت‌ها: %d اعمال‌شده، %d در انتظار\n", count($status['applied']), count($status['pending']));

    switch ($mode) {
        case 'status':
            echo "\nاعمال‌شده:\n";
            echo $status['applied'] === []
                ? "   (هیچ)\n"
                : implode('', array_map(static fn (string $name): string => "   ✓ {$name}\n", $status['applied']));
            echo "\nدر انتظار:\n";
            echo $status['pending'] === []
                ? "   (هیچ)\n"
                : implode('', array_map(static fn (string $name): string => "   • {$name}\n", $status['pending']));
            break;

        case 'up':
            $applied = $migrator->up();
            echo $applied === []
                ? "\nهمه مهاجرت‌ها از قبل اعمال شده‌اند.\n"
                : "\nاجرا شد:\n" . implode('', array_map(static fn (string $name): string => "   ✓ {$name}\n", $applied));
            break;

        case 'down':
            if ($status['batches'] === []) {
                echo "\nدسته‌ای برای بازگردانی وجود ندارد.\n";
                break;
            }
            $rolledBack = $migrator->down($batches);
            echo "\nبازگردانی شد:\n" . implode('', array_map(static fn (string $name): string => "   ↩ {$name}\n", $rolledBack));
            break;

        case 'fresh':
            if (!$force) {
                fwrite(STDERR, "\nبرای اجرای --fresh باید --force را هم بدهید: این کار همه جدول‌ها و داده‌ها را پاک می‌کند.\n");
                exit(2);
            }
            $applied = $migrator->fresh(true);
            echo "\nپایگاه‌داده از نو ساخته شد. اجرا شد:\n"
                . implode('', array_map(static fn (string $name): string => "   ✓ {$name}\n", $applied));
            break;
    }

    exit(0);
} catch (MigrationException $e) {
    $previous = $e->getPrevious();
    fwrite(STDERR, "\nخطا در مهاجرت {$e->migration}:\n   " . $e->getMessage() . "\n");
    if ($previous !== null) {
        fwrite(STDERR, '   جزئیات: ' . $previous->getMessage() . "\n");
    }
    fwrite(STDERR, "\nاگر مهاجرت نیمه‌کاره مانده است، وضعیت را با --status ببینید و قبل از ادامه، اثر آن را بررسی کنید.\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "\nخطا: " . $e->getMessage() . "\n");
    exit(1);
}
