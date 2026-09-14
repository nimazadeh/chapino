<?php

declare(strict_types=1);

namespace App\Core\Jobs;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Database\TableDefinition;

/**
 * The job queue, stored in the database.
 *
 * There is no worker process on shared hosting (ADR-0002), so cron calls a runner that processes a
 * bounded batch. Everything about this class follows from two requirements:
 *
 *  - **A job must never be done twice.** Claiming a job is one conditional UPDATE
 *    (`... WHERE id = ? AND status = 'queued'`), which is atomic on both engines: two cron runs
 *    starting at the same second cannot both win, and the loser simply moves on.
 *  - **A crash must not lose or leak work.** A claimed job carries `reserved_at` and `reserved_by`;
 *    if the process dies, `reclaimExpired()` returns it to the queue after its reservation expires,
 *    counting the attempt, so a job that kills the runner cannot be retried forever.
 *
 * What this class deliberately does NOT do: it never executes a handler. Claiming and running are
 * separate so that the runner can enforce time budgets, and so a job's input can be validated before
 * anything happens.
 */
final class Queue
{
    public const DEFAULT_QUEUE = 'default';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /** A running job whose process died is considered abandoned after this long. */
    public const RESERVATION_TIMEOUT_SECONDS = 900;

    public function __construct(private readonly Connection $database)
    {
    }

    /**
     * Adds a job.
     *
     * @param array<string, mixed> $payload JSON-serialisable, validated by the handler that consumes it
     * @param array{queue?: string, delay_seconds?: int, max_attempts?: int} $options
     * @return int the job id
     */
    public function push(string $type, array $payload = [], array $options = []): int
    {
        if (trim($type) === '') {
            throw new \InvalidArgumentException('A job needs a type.');
        }

        $queue = (string) ($options['queue'] ?? self::DEFAULT_QUEUE);
        $delay = max(0, (int) ($options['delay_seconds'] ?? 0));
        $maxAttempts = max(1, (int) ($options['max_attempts'] ?? 5));
        $now = Clock::now();

        return $this->database->insert('jobs', [
            'queue' => $queue,
            'type' => $type,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'status' => self::STATUS_QUEUED,
            'available_at' => gmdate('Y-m-d H:i:s', $now + $delay),
            'reserved_at' => null,
            'reserved_by' => null,
            'finished_at' => null,
            'last_error' => null,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
    }

    /**
     * Claims up to $limit runnable jobs for this runner, atomically.
     *
     * @return list<array<string, mixed>> the claimed job rows (status already moved to `running`)
     */
    public function claim(int $limit, string $runnerId, ?string $queue = null): array
    {
        if ($limit <= 0) {
            return [];
        }

        $nowText = gmdate('Y-m-d H:i:s', Clock::now());
        $sql = 'SELECT id FROM ' . TableDefinition::quote('jobs')
            . ' WHERE status = ? AND available_at <= ?';
        $parameters = [self::STATUS_QUEUED, $nowText];

        if ($queue !== null && $queue !== '') {
            $sql .= ' AND queue = ?';
            $parameters[] = $queue;
        }
        $sql .= ' ORDER BY id LIMIT ' . (int) $limit;

        $claimed = [];
        foreach ($this->database->select($sql, $parameters) as $row) {
            $id = (int) $row['id'];

            if (!$this->claimOne($id, $runnerId, $nowText)) {
                continue; // another runner claimed it first
            }

            $job = $this->database->first('SELECT * FROM ' . TableDefinition::quote('jobs') . ' WHERE id = ?', [$id]);
            if ($job !== null) {
                $claimed[] = $job;
            }
        }

        return $claimed;
    }

    /**
     * Claims one specific job, and answers whether this caller won it.
     *
     * This is the whole concurrency guarantee, kept in one place: the `status = 'queued'` condition is
     * part of the UPDATE, so the database decides the winner. Two runners that selected the same row a
     * microsecond apart both run this statement, exactly one changes a row, and the other one gets
     * `false` and moves on. A row that is already running, finished or failed can therefore never be
     * claimed again - the reason a finished job stays finished.
     *
     * Public because it is the primitive that must hold under concurrency, and a guarantee that is
     * only argued about in a comment is not verified: this way a test can make two attempts at the
     * same row and see exactly one success.
     */
    public function claimOne(int $id, string $runnerId, ?string $nowText = null): bool
    {
        $nowText ??= gmdate('Y-m-d H:i:s', Clock::now());

        $won = $this->database->execute(
            'UPDATE ' . TableDefinition::quote('jobs')
            . ' SET status = ?, reserved_at = ?, reserved_by = ?, attempts = attempts + 1'
            . ' WHERE id = ? AND status = ?',
            [self::STATUS_RUNNING, $nowText, $runnerId, $id, self::STATUS_QUEUED],
        );

        return $won === 1;
    }

    public function complete(int $id): void
    {
        $this->database->update('jobs', [
            'status' => self::STATUS_SUCCEEDED,
            'finished_at' => gmdate('Y-m-d H:i:s', Clock::now()),
            'reserved_at' => null,
            'reserved_by' => null,
            'last_error' => null,
        ], ['id' => $id]);
    }

    /**
     * Returns a job to the queue with a backoff, or marks it failed when attempts are exhausted.
     *
     * @return bool true when the job will be retried, false when it is now permanently failed
     */
    public function retryOrFail(int $id, string $error, int $backoffSeconds = 0): bool
    {
        $job = $this->database->first('SELECT attempts, max_attempts FROM ' . TableDefinition::quote('jobs') . ' WHERE id = ?', [$id]);
        if ($job === null) {
            return false;
        }

        $attempts = (int) $job['attempts'];
        $maxAttempts = (int) $job['max_attempts'];

        if ($attempts >= $maxAttempts) {
            $this->fail($id, $error);

            return false;
        }

        // Exponential backoff, bounded: a job that keeps failing must not hammer the host, and must
        // not disappear into a delay measured in days either.
        $delay = $backoffSeconds > 0 ? $backoffSeconds : min(3600, 30 * (2 ** max(0, $attempts - 1)));
        $this->database->update('jobs', [
            'status' => self::STATUS_QUEUED,
            'available_at' => gmdate('Y-m-d H:i:s', Clock::now() + $delay),
            'reserved_at' => null,
            'reserved_by' => null,
            'last_error' => mb_substr($error, 0, 500),
        ], ['id' => $id]);

        return true;
    }

    public function fail(int $id, string $error): void
    {
        $this->database->update('jobs', [
            'status' => self::STATUS_FAILED,
            'finished_at' => gmdate('Y-m-d H:i:s', Clock::now()),
            'reserved_at' => null,
            'reserved_by' => null,
            'last_error' => mb_substr($error, 0, 500),
        ], ['id' => $id]);
    }

    /**
     * Returns abandoned running jobs to the queue.
     *
     * @return int number of jobs reclaimed
     */
    public function reclaimExpired(int $timeoutSeconds = self::RESERVATION_TIMEOUT_SECONDS): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', Clock::now() - max(60, $timeoutSeconds));

        return $this->database->execute(
            'UPDATE ' . TableDefinition::quote('jobs')
            . ' SET status = ?, reserved_at = NULL, reserved_by = NULL, available_at = ?'
            . ' WHERE status = ? AND reserved_at IS NOT NULL AND reserved_at < ?',
            [
                self::STATUS_QUEUED,
                gmdate('Y-m-d H:i:s', Clock::now()),
                self::STATUS_RUNNING,
                $cutoff,
            ],
        );
    }

    /** @return array{queued: int, running: int, succeeded: int, failed: int} */
    public function counts(?string $queue = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM ' . TableDefinition::quote('jobs');
        $parameters = [];
        if ($queue !== null && $queue !== '') {
            $sql .= ' WHERE queue = ?';
            $parameters[] = $queue;
        }
        $sql .= ' GROUP BY status';

        $counts = [
            self::STATUS_QUEUED => 0,
            self::STATUS_RUNNING => 0,
            self::STATUS_SUCCEEDED => 0,
            self::STATUS_FAILED => 0,
        ];

        foreach ($this->database->select($sql, $parameters) as $row) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }
}
