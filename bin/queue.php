<?php

declare(strict_types=1);

/**
 * Queue inspection and operator actions.
 *
 * Why this exists in Phase 0: a cron queue that nobody can see is a queue nobody can trust. When a
 * job fails on the host, the operator needs to answer three questions without a database client -
 * "what is waiting?", "what failed, and why?", "how do I run it again?" - and this is that tool.
 *
 * Usage
 *   php bin/queue.php --status                 counts, plus the most recent jobs
 *   php bin/queue.php --show=12                one job in full, including its payload
 *   php bin/queue.php --push=maintenance.purge_rate_limits
 *   php bin/queue.php --push=some.type --payload='{"id":5}' --delay=60 --max-attempts=3
 *   php bin/queue.php --retry=12               put a failed job back in the queue
 *
 * CLI only: this is an operator surface, and it must never be reachable over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("queue.php must be run from the command line.\n");
}

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app', 'App\\');
App\Core\Clock::init();

use App\Core\Application;
use App\Core\Database\TableDefinition;
use App\Core\Jobs\Queue;

$options = [
    'status' => false,
    'show' => '',
    'push' => '',
    'payload' => '',
    'delay' => 0,
    'max-attempts' => 5,
    'queue' => '',
    'retry' => '',
];

foreach (array_slice($argv, 1) as $argument) {
    [$key, $value] = array_pad(explode('=', ltrim($argument, '-'), 2), 2, 'true');
    if (!array_key_exists($key, $options)) {
        fwrite(STDERR, "گزینه ناشناخته: {$argument}\n");
        exit(2);
    }
    $options[$key] = $value;
}

try {
    $app = new Application($root);
    $queue = $app->queue();
    $connection = $app->database();

    if ($options['push'] !== '') {
        $payload = [];
        if ($options['payload'] !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $options['payload'], true, 32);
            if (!is_array($decoded)) {
                fwrite(STDERR, "payload باید یک JSON معتبر باشد.\n");
                exit(2);
            }
            $payload = $decoded;
        }

        $id = $queue->push((string) $options['push'], $payload, [
            'queue' => (string) ($options['queue'] !== '' ? $options['queue'] : Queue::DEFAULT_QUEUE),
            'delay_seconds' => (int) $options['delay'],
            'max_attempts' => (int) $options['max-attempts'],
        ]);
        echo "کار ثبت شد: شناسه {$id}\n";
        echo "برای اجرا: php bin/cron.php\n";
        exit(0);
    }

    if ($options['retry'] !== '') {
        $id = (int) $options['retry'];
        $job = $connection->first('SELECT id, status FROM ' . TableDefinition::quote('jobs') . ' WHERE id = ?', [$id]);
        if ($job === null) {
            fwrite(STDERR, "کاری با شناسه {$id} پیدا نشد.\n");
            exit(1);
        }

        // An operator action, so it is deliberate and loud: the attempt counter is reset, and the job
        // becomes runnable immediately. Handlers are idempotent by contract, so re-running is safe.
        $connection->update('jobs', [
            'status' => Queue::STATUS_QUEUED,
            'attempts' => 0,
            'reserved_at' => null,
            'reserved_by' => null,
            'finished_at' => null,
            'available_at' => $connection->now(),
        ], ['id' => $id]);

        echo "کار {$id} برای اجرای دوباره در صف قرار گرفت (قبلاً: " . (string) $job['status'] . ").\n";
        exit(0);
    }

    if ($options['show'] !== '') {
        $id = (int) $options['show'];
        $job = $connection->first('SELECT * FROM ' . TableDefinition::quote('jobs') . ' WHERE id = ?', [$id]);
        if ($job === null) {
            fwrite(STDERR, "کاری با شناسه {$id} پیدا نشد.\n");
            exit(1);
        }

        printf("شناسه: %d\nنوع: %s\nصف: %s\nوضعیت: %s\nتلاش‌ها: %d از %d\nزمان اجرا: %s\nآخرین خطا: %s\npayload: %s\n",
            (int) $job['id'],
            (string) $job['type'],
            (string) $job['queue'],
            (string) $job['status'],
            (int) $job['attempts'],
            (int) $job['max_attempts'],
            (string) $job['available_at'],
            $job['last_error'] === null ? '—' : (string) $job['last_error'],
            (string) $job['payload'],
        );
        exit(0);
    }

    // Default action, and the only read-only one: report.
    $counts = $queue->counts();
    printf(
        "صف کار — در انتظار: %d ، در حال اجرا: %d ، موفق: %d ، ناموفق: %d\n",
        $counts['queued'],
        $counts['running'],
        $counts['succeeded'],
        $counts['failed'],
    );

    $rows = $connection->select(
        'SELECT id, type, status, attempts, available_at, last_error FROM '
        . TableDefinition::quote('jobs') . ' ORDER BY id DESC LIMIT 20',
    );

    if ($rows === []) {
        echo "هیچ کاری ثبت نشده است.\n";
        exit(0);
    }

    echo "\nآخرین کارها:\n";
    foreach ($rows as $row) {
        printf(
            "  #%d %-40s %-10s تلاش %d/%s %s%s\n",
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['status'],
            (int) $row['attempts'],
            '—',
            (string) $row['available_at'],
            $row['last_error'] === null ? '' : ' | ' . mb_substr((string) $row['last_error'], 0, 80),
        );
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "خطا: " . $e->getMessage() . "\n");
    exit(1);
}
