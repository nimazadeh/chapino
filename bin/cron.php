<?php

declare(strict_types=1);

/**
 * The scheduled entry point.
 *
 * Shared hosting offers exactly one reliable background mechanism: a cron job that runs a command on
 * a schedule. This script is that command. It must be safe to run twice at the same moment, safe to
 * run after a crash, and cheap when there is nothing to do.
 *
 * Usage
 *   php bin/cron.php                        default: up to 20 jobs or 50 seconds
 *   php bin/cron.php --limit=50 --seconds=120
 *   php bin/cron.php --queue=mail           process one queue
 *   php bin/cron.php --status               report queue state and exit
 *
 * Exit codes
 *   0  the run finished (jobs may still have failed - see the table and the log)
 *   1  the run could not start or crashed: configuration, database or an unexpected error
 *
 * A failed job is NOT an exit-code failure: cron mail from every failed job trains people to ignore
 * the mail. Failed jobs are counted in the summary, stored in `jobs` with their last error, and
 * logged as warnings - that is where an operator looks.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cron.php must be run from the command line (set it up as a cron job).\n");
}

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app', 'App\\');
App\Core\Clock::init();

use App\Core\Application;
use App\Core\Clock;

$options = ['limit' => 20, 'seconds' => 50, 'queue' => '', 'status' => false];
foreach (array_slice($argv, 1) as $argument) {
    [$key, $value] = array_pad(explode('=', ltrim($argument, '-'), 2), 2, 'true');
    if (!array_key_exists($key, $options)) {
        fwrite(STDERR, "گزینه ناشناخته: {$argument}\n");
        exit(2);
    }
    $options[$key] = $key === 'status' ? true : $value;
}

try {
    $app = new Application($root);

    // One runner at a time: a lock file with an exclusive, non-blocking lock. A second cron run that
    // starts while the first is still working exits immediately instead of competing for jobs.
    $lockPath = $app->config()->path('storage.path', 'storage') . '/tmp/cron.lock';
    $lockDirectory = dirname($lockPath);
    if (!is_dir($lockDirectory)) {
        @mkdir($lockDirectory, 0775, true);
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('فایل قفل کران ساخته نشد: ' . $lockPath);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        echo "یک اجرای دیگر کران در حال انجام است؛ این اجرا انجام نشد.\n";
        exit(0);
    }

    $queue = $app->queue();

    if ($options['status'] === true) {
        $counts = $queue->counts($options['queue'] !== '' ? (string) $options['queue'] : null);
        printf(
            "وضعیت صف: در انتظار %d ، در حال اجرا %d ، موفق %d ، ناموفق %d\n",
            $counts['queued'],
            $counts['running'],
            $counts['succeeded'],
            $counts['failed'],
        );
        flock($lock, LOCK_UN);
        exit(0);
    }

    $runnerId = sprintf('%s:%d', gethostname() ?: 'host', getmypid());
    $runner = $app->jobs($runnerId);

    $startedAt = Clock::now();
    $summary = $runner->run(
        max(1, (int) $options['limit']),
        max(1, (int) $options['seconds']),
        $options['queue'] !== '' ? (string) $options['queue'] : null,
    );

    $counts = $queue->counts($options['queue'] !== '' ? (string) $options['queue'] : null);

    printf(
        "کران: %d کار برداشته شد، %d موفق، %d برای تلاش دوباره، %d ناموفق، %d بازگردانی‌شده — %d میلی‌ثانیه (%s)\n",
        $summary['claimed'],
        $summary['succeeded'],
        $summary['retried'],
        $summary['failed'],
        $summary['reclaimed'],
        $summary['duration_ms'],
        $summary['stopped_because'],
    );
    printf(
        "صف: در انتظار %d ، در حال اجرا %d ، ناموفق %d\n",
        $counts['queued'],
        $counts['running'],
        $counts['failed'],
    );

    // Housekeeping runs every time: deleting an already-absent row is a no-op, so this is idempotent.
    $queue->push('maintenance.purge_rate_limits', [], ['queue' => 'maintenance']);
    $maintenance = $runner->run(5, max(1, 60 - (Clock::now() - $startedAt)), 'maintenance');
    if ($maintenance['claimed'] > 0) {
        printf(
            "نگهداری: %d کار اجرا شد (%d موفق، %d ناموفق)\n",
            $maintenance['claimed'],
            $maintenance['succeeded'],
            $maintenance['failed'],
        );
    }

    flock($lock, LOCK_UN);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "کران با خطا متوقف شد: " . $e->getMessage() . "\n");
    exit(1);
}
