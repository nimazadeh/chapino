<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Logger;
use App\Core\Security\Session;
use App\Core\Security\SessionException;
use Tests\TestCase;

/**
 * Session behaviour against a real database and real session storage.
 *
 * These are the properties that matter, and none of them is visible from reading a config file:
 *  - an anonymous visitor gets no database row (the cost argument), and
 *  - a logged-in session gets exactly one row, holding a hash and never the identifier itself;
 *  - logging in changes the identifier (fixation), logging out removes the row;
 *  - the idle and absolute timeouts are enforced from the stored row, not from the cookie;
 *  - a session whose row was deleted server-side loses its privileges immediately.
 */
final class SessionTest extends TestCase
{
    private string $storage;
    private Connection $database;
    private Config $config;

    protected function setUp(): void
    {
        $this->storage = $this->tempDir('chapino-session');
        $this->database = Connection::fromSettings([
            'driver' => 'sqlite',
            'sqlite_path' => $this->storage . '/test.sqlite',
        ], APP_ROOT);

        $schema = new Schema($this->database);
        (new Migrator($this->database, $schema, APP_ROOT . '/database/migrations'))->up();

        $this->config = Config::load($this->storage, [
            'storage' => ['path' => $this->storage],
            'security' => [
                'session_name' => 'chapino_test_session',
                'session_save_path' => $this->storage . '/sessions',
                'session_idle_timeout' => 3600,
                'session_absolute_timeout' => 86400,
            ],
        ]);

        $this->createUser(1);
        $this->createUser(7);
        $this->createUser(42);
        $this->resetPhpSession();
    }

    /**
     * A session row references its user: the foreign key is what makes "a session belongs to somebody
     * who exists" true in the database, not just in the code.
     */
    private function createUser(int $id): void
    {
        $this->database->insert('users', [
            'id' => $id,
            'mobile' => '0912000' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'role' => 'user',
            'owner_type' => 'personal',
            'is_active' => true,
            'created_at' => '2026-09-14 00:00:00',
            'updated_at' => '2026-09-14 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        $this->resetPhpSession();
    }

    private function session(): Session
    {
        return new Session($this->config, $this->database, new Logger($this->storage . '/logs', 'debug'), false);
    }

    /** PHP sessions are process-global, so each test starts from a clean slate. */
    private function resetPhpSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SESSION = [];
        session_id('');
    }

    private function sessionRows(?Connection $connection = null): int
    {
        return (int) ($connection ?? $this->database)->scalar('SELECT COUNT(*) FROM sessions');
    }

    public function testAnonymousSessionCreatesNoDatabaseRow(): void
    {
        $session = $this->session();
        $session->start();

        $this->assertTrue($session->isStarted());
        $this->assertFalse($session->isAuthenticated());
        $this->assertSame(0, $this->sessionRows(), 'an anonymous visitor must not cost a row');
    }

    public function testLoginStoresAHashOfTheIdentifierAndNeverTheIdentifierItself(): void
    {
        $session = $this->session();
        $session->start();
        $identifierBeforeLogin = session_id();
        $session->login(42, 'user');

        $this->assertTrue($session->isAuthenticated());
        $this->assertSame(42, $session->userId());
        $this->assertSame('user', $session->role());
        $this->assertSame(1, $this->sessionRows());

        $row = $this->database->first('SELECT token_hash, user_id, ip_address FROM sessions');
        $this->assertNotSame(null, $row);
        $this->assertSame(42, (int) $row['user_id']);
        $this->assertSame(hash('sha256', (string) session_id()), (string) $row['token_hash']);
        $this->assertFalse(
            (string) $row['token_hash'] === (string) session_id(),
            'the identifier is a bearer secret and must not be stored in clear text',
        );
        $this->assertFalse(
            (string) $row['token_hash'] === $identifierBeforeLogin,
            'the row must belong to the identifier issued at login',
        );
    }

    public function testLoginRegeneratesTheIdentifier(): void
    {
        $session = $this->session();
        $session->start();
        $before = (string) session_id();

        $session->login(7, 'user');

        $this->assertNotSame($before, (string) session_id(), 'session fixation must be impossible');
    }

    public function testLogoutRemovesTheRowAndThePrivileges(): void
    {
        $session = $this->session();
        $session->start();
        $session->login(7, 'user');

        $session->logout();

        $this->assertSame(0, $this->sessionRows(), 'a logged-out session must not be revocable later: it is gone');
        $this->assertSame(null, $session->userId());
    }

    public function testRevokingTheRowServerSideEndsThePrivilege(): void
    {
        $session = $this->session();
        $session->start();
        $session->login(7, 'user');

        // An administrator deletes the session row (the "log out everywhere" case).
        $this->database->delete('sessions', ['user_id' => 7]);
        Clock::unfreeze();

        $resumed = $this->session();
        $resumed->start();

        $this->assertFalse($resumed->isAuthenticated(), 'the cookie alone must not keep a session alive');
        $this->assertSame(0, $this->sessionRows());
    }

    public function testIdleTimeoutClearsTheSessionAndItsRow(): void
    {
        $session = $this->session();
        $session->start();
        $session->login(7, 'user');

        // Move the clock past the idle timeout: the row is the source of truth, not the cookie.
        Clock::freeze(Clock::now() + 3601);

        $resumed = $this->session();
        $resumed->start();

        $this->assertFalse($resumed->isAuthenticated());
        $this->assertSame(0, $this->sessionRows(), 'an expired session must not leave a usable row');
    }

    public function testAbsoluteTimeoutAppliesEvenToAnActiveSession(): void
    {
        $session = $this->session();
        $session->start();
        $session->login(7, 'user');

        // Stay active the whole time (so the idle timeout never fires), but exceed the hard limit.
        for ($minutes = 1; $minutes <= 3; $minutes++) {
            Clock::freeze(Clock::now() + 60);
            $active = $this->session();
            $active->start();
            $this->assertTrue($active->isAuthenticated(), "still valid after {$minutes} minute(s) of activity");
        }

        $this->config = Config::load($this->storage, [
            'security' => [
                'session_name' => 'chapino_test_session',
                'session_save_path' => $this->storage . '/sessions',
                'session_idle_timeout' => 3600,
                'session_absolute_timeout' => 120, // two minutes: shorter than the activity above
            ],
        ]);

        $resumed = $this->session();
        $resumed->start();

        $this->assertFalse($resumed->isAuthenticated(), 'an absolute limit must not be resettable by activity');
    }

    public function testAnonymousBrowsingWorksEvenBeforeMigrationsHaveRun(): void
    {
        // A freshly uploaded installation whose migrations have not run yet. Anonymous visitors must
        // still be served: the session middleware sits on the path of every request, so a database
        // dependency there would turn "you forgot one command" into a blank page for the whole site.
        $unmigrated = Connection::fromSettings([
            'driver' => 'sqlite',
            'sqlite_path' => $this->storage . '/unmigrated.sqlite',
        ], APP_ROOT);

        $session = new Session($this->config, $unmigrated, new Logger($this->storage . '/logs', 'debug'));
        $session->start();
        $session->put('probe', 'value');

        $this->assertNull($session->userId(), 'an anonymous session has no user');
        $this->assertSame('value', $session->get('probe'), 'session data must work without the database');
        $this->assertSame(
            0,
            (int) $unmigrated->scalar('SELECT COUNT(*) FROM sqlite_master WHERE type = ? AND name = ?', ['table', 'sessions']),
            'the sessions table must not have been created behind our back',
        );

        // Signing in does need storage, and there the failure must be the actionable one: run the
        // migrator. Anything else leaves the owner with a blank page and no next step.
        $exception = $this->assertThrows(
            \App\Core\Database\DatabaseException::class,
            static fn () => $session->login(1, 'user'),
        );
        $this->assertSame('database_schema_missing', $exception->errorCode);
        $this->assertTrue($exception->isSetupProblem());
        $this->assertStringContains('migrate.php', $exception->getMessage());
    }

    public function testLoggingInANonExistentUserFailsLoudlyInsteadOfSilently(): void
    {
        $session = $this->session();
        $session->start();

        // The foreign key rejects the row. This must surface as a foreign-key failure, not be mistaken
        // for a duplicate (which the session layer treats as "already written"): a session that claims
        // to be logged in as nobody is a security defect, and it would hide a real bug.
        $exception = $this->assertThrows(
            \App\Core\Database\DatabaseException::class,
            static fn () => $session->login(9999, 'user'),
        );

        $this->assertFalse($exception->isDuplicate(), 'a missing parent row is not a duplicate');
        $this->assertTrue($exception->isForeignKeyViolation());
        $this->assertSame('database_foreign_key', $exception->errorCode);
        $this->assertSame(0, $this->sessionRows(), 'nothing may be written for a user that does not exist');
    }

    public function testSessionUnavailableIsAnExplicitFailureNotASilentFallback(): void
    {
        $config = Config::load($this->storage, [
            'security' => ['session_save_path' => $this->storage . '/a-file-not-a-directory'],
        ]);
        file_put_contents($this->storage . '/a-file-not-a-directory', 'this is a file, not a directory');

        $session = new Session($config, $this->database, new Logger($this->storage . '/logs', 'debug'), false);

        $exception = $this->assertThrows(SessionException::class, static fn () => $session->start());
        $this->assertSame('session_path_unwritable', $exception->errorCode);
    }
}
