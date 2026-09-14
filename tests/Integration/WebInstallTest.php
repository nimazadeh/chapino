<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Database\Connection;
use App\Core\Install\AttemptLimiter;
use App\Core\Install\Installer;
use App\Core\Install\InstallException;
use App\Core\Install\InstallToken;
use App\Core\Install\WebInstaller;
use App\Core\Request;
use App\Core\Settings;
use Tests\TestCase;

/**
 * The panel-only installation path: the token, the installer and the maintenance page.
 *
 * What these tests are actually guarding, in order of importance:
 *
 *   1. **The token is never printed.** A page that prints its own token authorises whoever opens the
 *      URL first, which is how half-finished installations get taken over. The test knows the token
 *      (it reads the file) and asserts it does not appear in any response body.
 *   2. **Nothing is written before the database answers.** The installer's promise is that a failed
 *      connection leaves no `config/config.php`, so a second attempt starts from a known state.
 *   3. **The page disables itself.** Once a configuration file exists, the installer answers 404 -
 *      and the check is a property of the installer, not of a template.
 *   4. **Reading the token never executes it.** Storage is where an attacker who gets in writes; the
 *      token file is read with a parser, not with `require`.
 */
final class WebInstallTest extends TestCase
{
    // ---------------------------------------------------------------- helpers

    private function installRoot(): string
    {
        $root = $this->tempDir('chapino-webtest');
        mkdir($root . '/config', 0775, true);
        mkdir($root . '/storage', 0775, true);
        $this->copyDirectory(APP_ROOT . '/database/migrations', $root . '/database/migrations');
        $this->copyDirectory(APP_ROOT . '/database/seeds', $root . '/database/seeds');

        return $root;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0775, true);
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir($source . '/' . $entry)
                ? $this->copyDirectory($source . '/' . $entry, $destination . '/' . $entry)
                : copy($source . '/' . $entry, $destination . '/' . $entry);
        }
    }

    /**
     * An installation whose files live in the repository but whose state lives in a temporary directory.
     *
     * The application skeleton (`app/`, `database/`, the router) has to come from the real root - that
     * is where the code is - while configuration, database and storage stay isolated, exactly as the
     * page tests do it. Otherwise these tests would write `config/config.php` and a one-time token into
     * the working tree.
     */
    private function writeConfig(string $file, string $storage): void
    {
        file_put_contents($file, '<?php return ' . var_export([
            'app' => ['env' => 'local', 'debug' => false],
            'storage' => ['path' => $storage],
            'logging' => ['path' => $storage . '/logs', 'level' => 'debug'],
            'database' => ['driver' => 'sqlite', 'sqlite_path' => $storage . '/test.sqlite'],
            'security' => [
                'session_name' => 'chapino_webtest_session',
                'session_save_path' => $storage . '/sessions',
            ],
        ], true) . ';');

        putenv('CHAPINO_CONFIG=' . $file);
    }

    private function resetPhpSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SESSION = [];
        session_id('');
    }

    protected function tearDown(): void
    {
        $this->resetPhpSession();
        putenv('CHAPINO_CONFIG');
    }

    private function csrfFrom(string $html): string
    {
        preg_match('/name="_token" value="([0-9a-f]{64})"/', $html, $matches);

        return $matches[1] ?? '';
    }

    // ---------------------------------------------------------------- the token

    public function testTheTokenIsCreatedReadAndConsumed(): void
    {
        $root = $this->installRoot();
        $token = new InstallToken($root, 'storage');

        $this->assertFalse($token->exists(), 'nothing is created until it is asked for');

        $created = $token->create();

        $this->assertMatches('/^[0-9a-f]{64}$/', $created, 'the token must be 256 bits of hex');
        $this->assertTrue($token->exists());
        $this->assertSame($created, $token->read());
        $this->assertTrue($token->matches($created));
        $this->assertFalse($token->matches(''), 'an empty token never matches');
        $this->assertFalse($token->matches(null), 'a missing field never matches');
        $this->assertFalse($token->matches(str_repeat('a', 64)), 'a wrong token does not match');
        $this->assertFalse($token->matches(substr($created, 0, 63)), 'a truncated token does not match');

        $token->consume();

        $this->assertFalse($token->exists(), 'the token is single-use');
        $this->assertSame('', $token->read());
        $this->assertFalse($token->matches($created));
    }

    public function testReadingTheTokenNeverExecutesIt(): void
    {
        $root = $this->installRoot();
        $token = new InstallToken($root, 'storage');
        $token->create();

        // A file an attacker who reached storage could have written: valid enough to be read, with a
        // line that would print if the value were ever `require`d.
        file_put_contents($token->path(), "<?php\necho 'EXECUTED';\nreturn '" . str_repeat('a', 64) . "';\n");

        ob_start();
        $read = $token->read();
        $printed = (string) ob_get_clean();

        $this->assertSame('', $read, 'only files with the exact shape we wrote are accepted');
        $this->assertStringNotContains('EXECUTED', $printed, 'reading a token must never run it');
    }

    public function testWritingTheTokenFailsWithAnActionableMessageWhenStorageIsNotWritable(): void
    {
        $root = $this->installRoot();
        chmod($root . '/storage', 0500);

        try {
            $this->assertThrows(InstallException::class, static function () use ($root): void {
                (new InstallToken($root, 'storage'))->create();
            }, 'an unwritable storage directory must be reported, not ignored');
        } finally {
            chmod($root . '/storage', 0775);
        }
    }

    public function testFailedAttemptsAreCountedPerClientAndExpire(): void
    {
        $root = $this->installRoot();
        $limiter = new AttemptLimiter($root, 'storage');

        $this->assertFalse($limiter->isLockedOut('203.0.113.7'));

        for ($attempt = 0; $attempt < AttemptLimiter::MAX_ATTEMPTS; $attempt++) {
            $limiter->recordFailure('203.0.113.7');
        }

        $this->assertTrue($limiter->isLockedOut('203.0.113.7'), 'five wrong tokens must lock the client out');
        $this->assertFalse($limiter->isLockedOut('198.51.100.4'), 'the lockout is per client, not global');

        $limiter->clear('203.0.113.7');

        $this->assertFalse($limiter->isLockedOut('203.0.113.7'), 'a good token clears the count');
    }

    // ---------------------------------------------------------------- the installer

    public function testAFreshInstallationIsCreatedAndThenDisablesTheInstaller(): void
    {
        $root = $this->installRoot();
        $installer = new WebInstaller($root, 'storage');

        $this->assertFalse($installer->isInstalled());

        $result = $installer->install(['driver' => 'sqlite']);

        $this->assertTrue(is_file($root . '/config/config.php'), 'the configuration must exist');
        $this->assertTrue(count($result['migrations']) > 0, 'a fresh installation applies its migrations');
        $this->assertTrue(count($result['seeded']['added']) > 0, 'a fresh installation seeds its defaults');
        $this->assertTrue($installer->isInstalled());

        $connection = Connection::fromSettings(['driver' => 'sqlite', 'sqlite_path' => 'storage/database.sqlite'], $root);
        $this->assertTrue($connection->tableExists('users'), 'the schema must exist after installing');
        $this->assertTrue((new Settings($connection))->has('print.dpi'), 'defaults must be readable');

        // The whole point of writing the configuration last: this page is now gone, and it must be
        // gone for everyone, including a second attempt with a valid token.
        $exception = $this->assertThrows(InstallException::class, static function () use ($installer): void {
            $installer->install(['driver' => 'sqlite']);
        });
        $this->assertSame('already_installed', $exception->reason);
    }

    public function testAFailedDatabaseConnectionWritesNothing(): void
    {
        $root = $this->installRoot();
        $installer = new WebInstaller($root, 'storage');

        $exception = $this->assertThrows(\Throwable::class, static function () use ($installer, $root): void {
            // A database that cannot be opened. SQLite, not MySQL, and on purpose: opening a MySQL
            // socket aborts this WebAssembly runtime outright (see the environment notes in
            // project-context), so the failure path is exercised with a path that is a directory.
            $installer->install(['driver' => 'sqlite', 'sqlite_path' => $root . '/storage']);
        });

        $this->assertStringNotContains('password', strtolower($exception->getMessage()), 'never echo a credential back');
        $this->assertFalse(is_file($root . '/config/config.php'), 'no half-written installation may be left behind');
        $this->assertFalse($installer->isInstalled());
    }

    public function testARequiredCapabilityFailureStopsTheInstallBeforeAnythingIsWritten(): void
    {
        $root = $this->installRoot();
        // The session directory is a required check of its own (sessions carry bearer secrets, so a
        // directory the web server cannot write is not a warning). Making it read-only while storage
        // itself is writable isolates the requirement gate from the storage-preparation step.
        mkdir($root . '/storage/sessions', 0700, true);
        chmod($root . '/storage/sessions', 0500);

        try {
            $installer = new WebInstaller($root, 'storage');

            $exception = $this->assertThrows(InstallException::class, static function () use ($installer): void {
                $installer->install(['driver' => 'sqlite']);
            });

            $this->assertSame('requirements_failed', $exception->reason);
            $this->assertStringContains('نشست', $exception->getMessage(), 'the message must name what is missing');
            $this->assertFalse(is_file($root . '/config/config.php'), 'nothing may be written when a requirement fails');
        } finally {
            chmod($root . '/storage/sessions', 0700);
        }
    }

    public function testAnUnusableStorageDirectoryIsReportedAsAnOperatorProblem(): void
    {
        $root = $this->installRoot();
        chmod($root . '/storage', 0500);

        try {
            $installer = new WebInstaller($root, 'storage');

            $exception = $this->assertThrows(InstallException::class, static function () use ($installer): void {
                $installer->install(['driver' => 'sqlite']);
            });

            $this->assertSame('storage_not_writable', $exception->reason);
            $this->assertTrue(strlen($exception->getMessage()) > 10, 'the operator needs a message, not a code');
            $this->assertFalse(is_file($root . '/config/config.php'));
        } finally {
            chmod($root . '/storage', 0775);
        }
    }

    public function testUnsupportedDriverAndMissingFieldsAreRefusedWithActionableMessages(): void
    {
        $root = $this->installRoot();

        $driver = $this->assertThrows(InstallException::class, static function (): void {
            Installer::databaseSettings(['driver' => 'postgres']);
        });
        $this->assertSame('driver_unsupported', $driver->reason);

        $name = $this->assertThrows(InstallException::class, static function (): void {
            Installer::databaseSettings(['driver' => 'mysql', 'user' => 'chapino']);
        });
        $this->assertSame('database_name_missing', $name->reason);

        // A charset is interpolated into CREATE TABLE, so it must never come from free text.
        $charset = $this->assertThrows(InstallException::class, static function (): void {
            Installer::databaseSettings(['driver' => 'sqlite', 'charset' => "utf8mb4; DROP TABLE users"]);
        });
        $this->assertSame('charset_unsupported', $charset->reason);

        $port = $this->assertThrows(InstallException::class, static function (): void {
            Installer::databaseSettings([
                'driver' => 'mysql', 'user' => 'chapino', 'name' => 'chapino', 'port' => '70000',
            ]);
        });
        $this->assertSame('database_port_invalid', $port->reason);

        // Nothing above may have created an installation.
        $this->assertFalse(is_file($root . '/config/config.php'));
    }

    // ---------------------------------------------------------------- the maintenance page

    private function installedApp(): Application
    {
        $storage = $this->tempDir('chapino-maintenance');
        mkdir($storage . '/logs', 0775, true);
        mkdir($storage . '/sessions', 0700, true);

        $configFile = $this->tempDir('chapino-maintenance-config') . '/config.php';
        $this->writeConfig($configFile, $storage);
        $this->resetPhpSession();

        (new Installer(APP_ROOT))->migrate([
            'driver' => 'sqlite',
            'sqlite_path' => $storage . '/test.sqlite',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        return new Application(APP_ROOT);
    }

    public function testTheMaintenancePageCreatesATokenAndNeverPrintsIt(): void
    {
        $app = $this->installedApp();
        $response = $app->handle(Request::create('GET', '/maintenance'));

        $this->assertSame(200, $response->status);
        $this->assertStringContains('توکن', $response->body);

        $token = new InstallToken($app->root(), (string) $app->config()->get('storage.path', 'storage'));
        $this->assertTrue($token->exists(), 'opening the page prepares the token the operator has to read');

        $secret = $token->read();
        $this->assertMatches('/^[0-9a-f]{64}$/', $secret);
        $this->assertStringNotContains($secret, $response->body, 'the token must never appear in the page');
        $this->assertStringContains('install-token.php', $response->body, 'the operator is told which file to open');
    }

    public function testTheMaintenancePageRequiresTheTokenAndThenShowsTheInstallationState(): void
    {
        $app = $this->installedApp();

        $page = $app->handle(Request::create('GET', '/maintenance'));
        $csrf = $this->csrfFrom($page->body);
        $this->assertMatches('/^[0-9a-f]{64}$/', $csrf, 'the form must carry a session-bound token');

        $token = new InstallToken($app->root(), (string) $app->config()->get('storage.path', 'storage'));

        $wrong = $app->handle(Request::create('POST', '/maintenance', [], [
            '_token' => $csrf,
            'action' => 'authorize',
            'token' => str_repeat('b', 64),
        ]));
        $this->assertSame(401, $wrong->status, 'a wrong token must not authorise anything');
        $this->assertStringNotContains('وضعیت مهاجرت‌ها', $wrong->body, 'the status must stay hidden until authorised');

        $right = $app->handle(Request::create('POST', '/maintenance', [], [
            '_token' => $csrf,
            'action' => 'authorize',
            'token' => $token->read(),
        ]));
        $this->assertSame(200, $right->status);
        $this->assertStringContains('وضعیت مهاجرت‌ها', $right->body);
    }

    public function testRunningMigrationsConsumesTheTokenAndLocksThePage(): void
    {
        $app = $this->installedApp();
        $storage = (string) $app->config()->get('storage.path', 'storage');
        $token = new InstallToken($app->root(), $storage);

        $page = $app->handle(Request::create('GET', '/maintenance'));
        $csrf = $this->csrfFrom($page->body);

        $app->handle(Request::create('POST', '/maintenance', [], [
            '_token' => $csrf,
            'action' => 'authorize',
            'token' => $token->read(),
        ]));

        $run = $app->handle(Request::create('POST', '/maintenance', [], [
            '_token' => $csrf,
            'action' => 'migrate',
        ]));

        preg_match('/اجرای این کار ناموفق بود: ([^<]*)/u', $run->body, $failure);
        $this->assertSame(200, $run->status, 'maintenance action failed: ' . ($failure[1] ?? 'no message'));
        $this->assertStringContains('مهاجرت در انتظاری نبود', $run->body, 'nothing pending is reported as nothing pending');
        $this->assertFalse($token->exists(), 'the token is single-use: applying an update locks the page again');
    }
}
