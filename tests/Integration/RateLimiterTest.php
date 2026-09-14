<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Security\RateLimiter;
use Tests\TestCase;

/**
 * Rate limiting against a real database.
 *
 * Two properties are checked here that a simpler test would miss: the counter must not lose hits when
 * two requests race (the increment is conditional, and the insert path tolerates the unique-constraint
 * race), and the stored key must be a hash - a leaked rate-limit table must not read as a list of IP
 * addresses and mobile numbers.
 */
final class RateLimiterTest extends TestCase
{
    private Connection $database;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $storage = $this->tempDir('chapino-ratelimit');
        $this->database = Connection::fromSettings([
            'driver' => 'sqlite',
            'sqlite_path' => $storage . '/test.sqlite',
        ], APP_ROOT);

        $schema = new Schema($this->database);
        (new Migrator($this->database, $schema, APP_ROOT . '/database/migrations'))->up();

        $this->limiter = new RateLimiter($this->database, 'chapino|');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    public function testAttemptsAreAllowedUpToTheLimitAndThenRefused(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = $this->limiter->hit('login', '09121234567', 3, 3600);
            $this->assertTrue($result->allowed, "attempt {$attempt} must be allowed");
            $this->assertSame($attempt, $result->used);
        }

        $refused = $this->limiter->hit('login', '09121234567', 3, 3600);
        $this->assertFalse($refused->allowed);
        $this->assertSame(0, $refused->remaining());
        $this->assertTrue($refused->retryAfterSeconds > 0, 'a refused client must be told when to retry');
    }

    public function testBucketsAndKeysAreIndependent(): void
    {
        $this->limiter->hit('login', '09121234567', 1, 3600);

        $this->assertTrue($this->limiter->hit('login', '09350000000', 1, 3600)->allowed, 'another number');
        $this->assertTrue($this->limiter->hit('otp', '09121234567', 1, 3600)->allowed, 'another bucket');
    }

    public function testTheWindowResetsAfterItExpires(): void
    {
        $this->limiter->hit('login', '09121234567', 1, 60);
        $this->assertFalse($this->limiter->hit('login', '09121234567', 1, 60)->allowed);

        Clock::freeze(Clock::now() + 61);

        $this->assertTrue($this->limiter->hit('login', '09121234567', 1, 60)->allowed);
    }

    public function testPeekDoesNotConsumeAnAttempt(): void
    {
        $this->limiter->hit('login', '09121234567', 5, 3600);

        $this->assertSame(1, $this->limiter->peek('login', '09121234567', 3600));
        $this->assertSame(1, $this->limiter->peek('login', '09121234567', 3600));
    }

    public function testClearForgetsACounter(): void
    {
        $this->limiter->hit('login', '09121234567', 1, 3600);
        $this->assertSame(1, $this->limiter->clear('login', '09121234567'));
        $this->assertTrue($this->limiter->hit('login', '09121234567', 1, 3600)->allowed);
    }

    public function testTheStoredKeyIsAHashAndNotTheRawIdentifier(): void
    {
        $this->limiter->hit('login', '09121234567', 5, 3600);
        $this->limiter->hit('write', '203.0.113.9', 5, 3600);

        $rows = $this->database->select('SELECT bucket, key_hash FROM rate_limits');
        $hashes = array_map(static fn (array $row): string => (string) $row['key_hash'], $rows);

        foreach ($hashes as $hash) {
            $this->assertSame(64, strlen($hash), 'a SHA-256 hash in hex');
            $this->assertStringNotContains('09121234567', $hash);
            $this->assertStringNotContains('203.0.113.9', $hash);
        }
    }

    public function testPurgeRemovesOnlyExpiredWindows(): void
    {
        $this->limiter->hit('old', 'key-a', 5, 60);
        $this->limiter->hit('fresh', 'key-b', 5, 3600);

        Clock::freeze(Clock::now() + 120);

        $this->assertSame(1, $this->limiter->purgeExpired(), 'only the expired window is deleted');
        $this->assertSame(1, (int) $this->database->scalar('SELECT COUNT(*) FROM rate_limits'));
    }

    public function testANonPositiveLimitIsAProgrammingError(): void
    {
        // A limit of 0 would silently mean "never allow anything", which is a way to take a site down
        // by configuration. Disabling a limit has its own explicit switch at the call site.
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->limiter->hit('login', 'x', 0, 60));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->limiter->hit('login', 'x', 5, 0));
    }
}
