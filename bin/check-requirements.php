<?php

declare(strict_types=1);

/**
 * Environment report.
 *
 * Answers "what does this environment actually provide?" for a local machine, a staging box or the
 * real host, so that decisions rest on measured facts instead of assumptions (`O-2`, `O-3`, `O-20`).
 *
 * Usage
 *   php bin/check-requirements.php
 *   php bin/check-requirements.php --config=/path/to/config.php
 *
 * Safe by design: it writes nothing, changes nothing, and prints no credentials - connection
 * results are reduced to a boolean plus a server version string.
 *
 * Exit code 0 means every required item is satisfied.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("check-requirements.php must be run from the command line.\n");
}

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app', 'App\\');
App\Core\Clock::init();

use App\Core\Config;
use App\Core\Install\RequirementsChecker;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--config=')) {
        putenv('CHAPINO_CONFIG=' . substr($argument, 9));
    } else {
        fwrite(STDERR, "گزینه ناشناخته: {$argument}\n");
        exit(2);
    }
}

$config = null;
try {
    $config = Config::load($root);
} catch (Throwable $e) {
    // No configuration yet (a fresh clone): the report still runs and says so, because that is
    // exactly the state in which someone wants to know what the environment provides.
    fwrite(STDOUT, "توجه: فایل پیکربندی خوانده نشد ({$e->getMessage()})\n\n");
}

$checker = new RequirementsChecker($root, $config);

echo "گزارش محیط اجرا — chapino\n";
echo str_repeat('=', 64) . "\n";

$requiredFailures = 0;
$optionalWarnings = 0;

foreach ($checker->run() as $check) {
    $marker = $check['ok'] ? 'OK  ' : ($check['required'] ? 'FAIL' : 'WARN');
    printf(
        "%s  %-32s %s\n",
        $marker,
        $check['name'],
        $check['detail'],
    );

    if (!$check['ok']) {
        if ($check['required']) {
            $requiredFailures++;
        } else {
            $optionalWarnings++;
        }
    }
}

echo str_repeat('-', 64) . "\n";
printf("مورد نیاز برقرارنشده: %d ، هشدار اختیاری: %d\n", $requiredFailures, $optionalWarnings);

if ($requiredFailures === 0) {
    echo "\nنتیجه: این محیط برای اجرای برنامه آماده است.\n";
    exit(0);
}

echo "\nنتیجه: این محیط هنوز آماده نیست. موارد FAIL بالا را رفع کنید و دوباره اجرا کنید.\n";
exit(1);
