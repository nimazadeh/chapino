<?php

declare(strict_types=1);

namespace App\Core\Jobs;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Logger;

/**
 * Executes queued jobs in a bounded batch. Called by cron, never by a web request.
 *
 * Bounds are the whole point: shared hosting kills long processes and counts CPU seconds, so the
 * runner stops after N jobs or after N seconds, whichever comes first, and reports why it stopped.
 * The next cron run continues where this one finished, because the queue is in the database.
 *
 * Failure policy, stated explicitly (backend rule, section 8):
 *  - an unknown job type is failed immediately with a clear reason: retrying a type nobody registered
 *    can only burn CPU, and a silent skip would hide a deployment mistake;
 *  - a handler that throws causes a retry with backoff, up to the job's `max_attempts`;
 *  - a job that exhausts its attempts becomes `failed` and stays visible in the table, with the last
 *    error, for a human to look at. Nothing is deleted.
 *
 * There is no per-job timeout: PHP without pcntl cannot interrupt a running function. Handlers must
 * therefore do bounded work, and the runner enforces the batch budget between jobs. This is a known
 * limitation and it is recorded in the report rather than hidden behind a promising option name.
 */
final class JobRunner
{
    /** @param array<string, callable> $handlers type => callable(array $payload, Connection $db, Logger $logger): void */
    public function __construct(
        private readonly Connection $database,
        private readonly Queue $queue,
        private readonly Logger $logger,
        private readonly array $handlers,
        private readonly string $runnerId = 'cron',
    ) {
    }

    /**
     * Runs a batch.
     *
     * @return array{claimed: int, succeeded: int, retried: int, failed: int, reclaimed: int, duration_ms: int, stopped_because: string}
     */
    public function run(int $maxJobs = 20, int $maxSeconds = 50, ?string $queue = null): array
    {
        $startedAt = Clock::now();
        $result = [
            'claimed' => 0,
            'succeeded' => 0,
            'retried' => 0,
            'failed' => 0,
            'reclaimed' => $this->queue->reclaimExpired(),
            'duration_ms' => 0,
            // The reason is part of the result on purpose: an operator reading the cron output must be
            // able to tell "there was nothing to do" from "there is more waiting than one run handles".
            'stopped_because' => 'batch_empty',
        ];

        $batch = $this->queue->claim($maxJobs, $this->runnerId, $queue);
        $result['claimed'] = count($batch);

        if ($batch === []) {
            $result['duration_ms'] = (Clock::now() - $startedAt) * 1000;

            return $result;
        }

        // Anything claimed and not stopped early means the batch was drained; whether more work is
        // waiting is decided below by comparing the batch size with the limit.
        $result['stopped_because'] = count($batch) >= $maxJobs ? 'job_limit_reached' : 'batch_exhausted';

        foreach ($batch as $index => $job) {
            if ($index > 0 && (Clock::now() - $startedAt) >= $maxSeconds) {
                $result['stopped_because'] = 'time_budget_reached';
                break;
            }

            $outcome = $this->runOne($job);
            $result[$outcome]++;

            if ($outcome === 'retried' || $outcome === 'failed') {
                // The runner reports which job misbehaved; the detail is already in last_error.
                $this->logger->warning('job_' . $outcome, [
                    'job_id' => (int) $job['id'],
                    'type' => (string) $job['type'],
                ]);
            }
        }

        $result['duration_ms'] = (Clock::now() - $startedAt) * 1000;

        return $result;
    }

    /**
     * Runs one job.
     *
     * @param array<string, mixed> $job
     * @return 'succeeded'|'retried'|'failed'
     */
    private function runOne(array $job): string
    {
        $id = (int) $job['id'];
        $type = (string) $job['type'];

        $handler = $this->handlers[$type] ?? null;
        if ($handler === null) {
            $this->queue->fail($id, 'نوع کار ثبت نشده است: ' . $type);

            return 'failed';
        }

        try {
            $payload = $this->decodePayload((string) ($job['payload'] ?? ''));
        } catch (\JsonException $e) {
            $this->queue->fail($id, 'payload نامعتبر است: ' . $e->getMessage());

            return 'failed';
        }

        try {
            $handler($payload, $this->database, $this->logger);
            $this->queue->complete($id);

            return 'succeeded';
        } catch (\Throwable $e) {
            // The message is stored for the operator; it may contain internal detail, so it is never
            // sent to a client - only shown through the admin surface in a later phase (security rule).
            $message = $e::class . ': ' . $e->getMessage();

            return $this->queue->retryOrFail($id, $message) ? 'retried' : 'failed';
        }
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $json): array
    {
        if ($json === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('payload must be a JSON object');
        }

        return $decoded;
    }
}
