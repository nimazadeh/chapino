<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Jobs\JobRunner;
use App\Core\Jobs\Queue;
use App\Core\Logger;
use Tests\TestCase;

/**
 * The queue and the runner.
 *
 * On shared hosting cron can fire while a previous run is still working, and a process can be killed
 * in the middle of a job. So the properties that matter here are: one job is claimed by exactly one
 * runner; a crashed run leaves the job recoverable; a failing job is retried with backoff and then
 * becomes visible instead of looping forever; and an unknown job type fails loudly rather than
 * burning CPU forever.
 */
final class QueueTest extends TestCase
{
    private string $storage;
    private Connection $database;
    private Queue $queue;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->storage = $this->tempDir('chapino-queue');
        $this->database = Connection::fromSettings([
            'driver' => 'sqlite',
            'sqlite_path' => $this->storage . '/test.sqlite',
        ], APP_ROOT);

        $schema = new Schema($this->database);
        (new Migrator($this->database, $schema, APP_ROOT . '/database/migrations'))->up();

        $this->queue = new Queue($this->database);
        $this->logger = new Logger($this->storage . '/logs', 'debug');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    /** @param array<string, callable> $handlers */
    private function runner(array $handlers, string $id = 'test-runner'): JobRunner
    {
        return new JobRunner($this->database, $this->queue, $this->logger, $handlers, $id);
    }

    public function testPushedJobIsClaimedAndCompleted(): void
    {
        $id = $this->queue->push('demo', ['value' => 'سلام']);

        $this->assertSame(['queued' => 1, 'running' => 0, 'succeeded' => 0, 'failed' => 0], $this->queue->counts());

        $claimed = $this->queue->claim(10, 'runner-a');
        $this->assertCount(1, $claimed);
        $this->assertSame($id, (int) $claimed[0]['id']);
        $this->assertSame(1, (int) $claimed[0]['attempts'], 'claiming counts an attempt');
        $this->assertSame('سلام', json_decode((string) $claimed[0]['payload'], true)['value'], 'Persian payloads survive');

        $this->queue->complete($id);
        $this->assertSame(['queued' => 0, 'running' => 0, 'succeeded' => 1, 'failed' => 0], $this->queue->counts());
    }

    public function testTheAtomicClaimRefusesARowThatIsNoLongerQueued(): void
    {
        // The real race: two cron runs select the same job before either has claimed it. The winner is
        // decided by the database inside the UPDATE, not by the select, so the losing attempt must come
        // back false - that is what stops a finished job from being executed a second time.
        $id = $this->queue->push('demo');

        $this->assertTrue($this->queue->claimOne($id, 'runner-a'), 'the first attempt wins');
        $this->assertFalse($this->queue->claimOne($id, 'runner-b'), 'a row already running cannot be won again');
        $this->assertFalse($this->queue->claimOne($id, 'runner-b'), 'and it stays that way');
        $this->assertSame(1, (int) $this->database->scalar('SELECT attempts FROM jobs WHERE id = ?', [$id]));
        $this->assertSame('runner-a', (string) $this->database->scalar('SELECT reserved_by FROM jobs WHERE id = ?', [$id]));

        $this->queue->complete($id);
        $this->assertFalse($this->queue->claimOne($id, 'runner-c'), 'a succeeded job is not re-runnable by accident');
        $this->assertSame('succeeded', (string) $this->database->scalar('SELECT status FROM jobs WHERE id = ?', [$id]));
    }

    public function testAJobIsClaimedByExactlyOneRunner(): void
    {
        $this->queue->push('demo');

        $first = $this->queue->claim(10, 'runner-a');
        $second = $this->queue->claim(10, 'runner-b');

        $this->assertCount(1, $first);
        $this->assertCount(0, $second, 'two cron runs must not both execute the same job');
    }

    public function testClaimSkipsJobsThatAreNotQueued(): void
    {
        $running = $this->queue->push('demo');
        $succeeded = $this->queue->push('demo');
        $failed = $this->queue->push('demo');

        $this->queue->claim(1, 'runner-a');           // the oldest job is now running
        $this->queue->complete($succeeded);
        $this->queue->fail($failed, 'deliberate');

        $claimed = $this->queue->claim(10, 'runner-b');

        $this->assertCount(0, $claimed, 'only queued work may be claimed');
        $this->assertSame($running, (int) $this->database->scalar('SELECT MIN(id) FROM jobs WHERE status = ?', [Queue::STATUS_RUNNING]));
        $counts = $this->queue->counts();
        $this->assertSame(0, $counts['queued']);
        $this->assertSame(1, $counts['running']);
        $this->assertSame(1, $counts['succeeded']);
        $this->assertSame(1, $counts['failed']);
    }

    public function testScheduledJobIsNotRunnableUntilItsTime(): void
    {
        $this->queue->push('demo', [], ['delay_seconds' => 120]);

        $this->assertCount(0, $this->queue->claim(10, 'runner-a'), 'a delayed job must not run early');

        Clock::freeze(Clock::now() + 121);
        $this->assertCount(1, $this->queue->claim(10, 'runner-a'));
    }

    public function testFailingHandlerIsRetriedWithBackoffThenMarkedFailed(): void
    {
        $id = $this->queue->push('always_fails', [], ['max_attempts' => 2]);
        $failing = ['always_fails' => static function (): void {
            throw new \RuntimeException('boom');
        }];

        $first = $this->runner($failing)->run(10, 10);
        $this->assertSame(['claimed' => 1, 'retried' => 1], ['claimed' => $first['claimed'], 'retried' => $first['retried']]);

        $row = $this->database->first('SELECT status, attempts, available_at, last_error FROM jobs WHERE id = ?', [$id]);
        $this->assertSame(Queue::STATUS_QUEUED, (string) $row['status']);
        $this->assertStringContains('boom', (string) $row['last_error'], 'the error must be visible to an operator');

        // Backoff: it must not be runnable again immediately.
        $this->assertCount(0, $this->queue->claim(10, 'runner-b'));

        Clock::freeze(Clock::now() + 3600);
        $second = $this->runner($failing)->run(10, 10);

        $this->assertSame(1, $second['failed'], 'attempts exhausted: the job becomes failed, not a loop');
        $this->assertSame(Queue::STATUS_FAILED, (string) $this->database->scalar('SELECT status FROM jobs WHERE id = ?', [$id]));
    }

    public function testUnknownJobTypeFailsImmediatelyWithAReason(): void
    {
        $id = $this->queue->push('nobody_registered_this');

        $summary = $this->runner(['other' => static fn (): null => null])->run(10, 10);

        $this->assertSame(1, $summary['failed']);
        $row = $this->database->first('SELECT status, last_error FROM jobs WHERE id = ?', [$id]);
        $this->assertSame(Queue::STATUS_FAILED, (string) $row['status']);
        $this->assertStringContains('nobody_registered_this', (string) $row['last_error']);
    }

    public function testInvalidPayloadFailsWithoutRunningTheHandler(): void
    {
        $ran = false;
        $id = $this->queue->push('demo');
        // A payload that is valid JSON but not an object: a queue message is untrusted input.
        $this->database->update('jobs', ['payload' => '"just a string"'], ['id' => $id]);

        $summary = $this->runner([
            'demo' => static function () use (&$ran): void {
                $ran = true;
            },
        ])->run(10, 10);

        $this->assertSame(1, $summary['failed']);
        $this->assertFalse($ran, 'the handler must not run on input it cannot trust');
    }

    public function testAbandonedRunningJobIsReclaimed(): void
    {
        $id = $this->queue->push('demo');
        $this->queue->claim(1, 'runner-that-died');

        // The process was killed: the reservation is older than the timeout.
        $this->database->update('jobs', ['reserved_at' => gmdate('Y-m-d H:i:s', Clock::now() - 3600)], ['id' => $id]);

        $this->assertSame(1, $this->queue->reclaimExpired());

        $counts = $this->queue->counts();
        $this->assertSame(1, $counts['queued']);
        $this->assertSame(0, $counts['running']);
    }

    public function testRunnerStopsAtItsJobLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queue->push('demo');
        }

        $ran = 0;
        $summary = $this->runner([
            'demo' => static function () use (&$ran): void {
                $ran++;
            },
        ])->run(2, 10);

        $this->assertSame(2, $summary['claimed'], 'a batch is bounded: cron must return quickly');
        $this->assertSame(2, $ran);
        $this->assertSame('job_limit_reached', $summary['stopped_because']);
        $this->assertSame(3, $this->queue->counts()['queued'], 'the rest waits for the next run');
    }

    public function testStopReasonTellsAnEmptyQueueFromADrainedOne(): void
    {
        $noop = ['demo' => static fn (): null => null];

        $empty = $this->runner($noop)->run(10, 10);
        $this->assertSame(0, $empty['claimed']);
        $this->assertSame('batch_empty', $empty['stopped_because'], 'nothing was waiting');

        $this->queue->push('demo');
        $this->queue->push('demo');
        $drained = $this->runner($noop)->run(10, 10);
        $this->assertSame(2, $drained['claimed']);
        $this->assertSame(
            'batch_exhausted',
            $drained['stopped_because'],
            'work was done and nothing was left behind - not the same as "there was nothing to do"',
        );
    }

    public function testHandlerThatSucceedsIsSafeToRunTwice(): void
    {
        // The real maintenance handler: deleting already-absent rows is a no-op, so a repeated run
        // (after a crash between the work and the bookkeeping) changes nothing.
        $handlers = require APP_ROOT . '/app/Jobs/handlers.php';
        $this->database->insert('rate_limits', [
            'bucket' => 'demo',
            'key_hash' => str_repeat('a', 64),
            'window_started_at' => Clock::now() - 7200,
            'hits' => 5,
            'expires_at' => gmdate('Y-m-d H:i:s', Clock::now() - 3600),
        ]);

        $this->queue->push('maintenance.purge_rate_limits');
        $first = $this->runner($handlers)->run(10, 10);
        $this->assertSame(1, $first['succeeded']);

        $this->queue->push('maintenance.purge_rate_limits');
        $second = $this->runner($handlers)->run(10, 10);

        $this->assertSame(1, $second['succeeded']);
        $this->assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM rate_limits'));
    }
}
