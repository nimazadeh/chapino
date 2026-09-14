<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Database\DatabaseException;
use App\Core\Database\TableDefinition;

/**
 * Database-backed rate limiting.
 *
 * Why the database and not a cache: shared hosting gives no Redis and no APCu guarantee
 * (ADR-0002), and a limit that only exists in one process is not a limit. The `rate_limits` table
 * uses a fixed window per counter, with the window start stored in the key so old windows can be
 * pruned by cron instead of growing forever.
 *
 * Privacy: the counter key (an IP address, a mobile number, an account id) is stored as a SHA-256
 * hash. A leaked `rate_limits` table then shows activity counts without a list of who was calling -
 * and the same bucket/key can still be found again for the next request.
 *
 * Atomicity: two simultaneous requests must not both be the "first" hit of a window. The increment
 * is a conditional UPDATE, and the INSERT path tolerates the unique-constraint race by falling back
 * to the UPDATE - so the count is never lost and never double-created.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Connection $database,
        private readonly string $namespace = '',
    ) {
    }

    /**
     * Counts one attempt and reports whether it is allowed.
     *
     * The attempt is counted even when it is refused: sliding the window forward on abuse would let
     * an attacker reset their own limit by hammering harder.
     */
    public function hit(string $bucket, string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('A rate limit must allow at least one attempt.');
        }
        if ($windowSeconds <= 0) {
            throw new \InvalidArgumentException('A rate-limit window must be positive.');
        }

        $now = Clock::now();
        $windowStart = $now - ($now % $windowSeconds);
        $keyHash = $this->hash($this->namespace . $bucket . ':' . $key);

        $used = $this->increment($bucket, $keyHash, $windowStart, $now, $windowSeconds);
        $retryAfter = max(0, ($windowStart + $windowSeconds) - $now);

        return new RateLimitResult($used <= $limit, $limit, $used, $windowSeconds, $retryAfter);
    }

    /** Reads the current count without counting an attempt (used to show "attempts left"). */
    public function peek(string $bucket, string $key, int $windowSeconds): int
    {
        $now = Clock::now();
        $windowStart = $now - ($now % $windowSeconds);

        $value = $this->database->scalar(
            'SELECT hits FROM ' . TableDefinition::quote('rate_limits')
            . ' WHERE bucket = ? AND key_hash = ? AND window_started_at = ?',
            [$bucket, $this->hash($this->namespace . $bucket . ':' . $key), $windowStart],
        );

        return (int) $value;
    }

    /** Forgets a counter: called after a successful login or a verified code. */
    public function clear(string $bucket, string $key): int
    {
        return $this->database->execute(
            'DELETE FROM ' . TableDefinition::quote('rate_limits') . ' WHERE bucket = ? AND key_hash = ?',
            [$bucket, $this->hash($this->namespace . $bucket . ':' . $key)],
        );
    }

    /**
     * Deletes windows that have fully expired.
     *
     * Run from cron: without this the table grows without bound, which on shared hosting is a
     * database-quota problem, not a theoretical one.
     */
    public function purgeExpired(): int
    {
        return $this->database->execute(
            'DELETE FROM ' . TableDefinition::quote('rate_limits') . ' WHERE expires_at < ?',
            [gmdate('Y-m-d H:i:s', Clock::now())],
        );
    }

    private function increment(string $bucket, string $keyHash, int $windowStart, int $now, int $windowSeconds): int
    {
        $table = TableDefinition::quote('rate_limits');
        $expiresAt = gmdate('Y-m-d H:i:s', $windowStart + $windowSeconds);

        $updated = $this->database->execute(
            sprintf('UPDATE %s SET hits = hits + 1, expires_at = ? WHERE bucket = ? AND key_hash = ? AND window_started_at = ?', $table),
            [$expiresAt, $bucket, $keyHash, $windowStart],
        );

        if ($updated > 0) {
            return $this->currentHits($bucket, $keyHash, $windowStart);
        }

        try {
            $this->database->insert('rate_limits', [
                'bucket' => $bucket,
                'key_hash' => $keyHash,
                'window_started_at' => $windowStart,
                'hits' => 1,
                'expires_at' => $expiresAt,
            ]);

            return 1;
        } catch (DatabaseException $e) {
            if (!$e->isDuplicate()) {
                throw $e;
            }

            // Another request created the row between our UPDATE and our INSERT.
            $this->database->execute(
                sprintf('UPDATE %s SET hits = hits + 1 WHERE bucket = ? AND key_hash = ? AND window_started_at = ?', $table),
                [$bucket, $keyHash, $windowStart],
            );

            return $this->currentHits($bucket, $keyHash, $windowStart);
        }
    }

    private function currentHits(string $bucket, string $keyHash, int $windowStart): int
    {
        return (int) $this->database->scalar(
            'SELECT hits FROM ' . TableDefinition::quote('rate_limits')
            . ' WHERE bucket = ? AND key_hash = ? AND window_started_at = ?',
            [$bucket, $keyHash, $windowStart],
        );
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
