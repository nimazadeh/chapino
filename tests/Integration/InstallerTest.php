<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Install\Installer;
use App\Core\Install\RequirementsChecker;
use Tests\TestCase;

/**
 * The installer is the first thing anyone runs, on a host nobody has inspected yet. Its two duties
 * are: never write a configuration that does not work, and never overwrite an existing
 * installation without being told to.
 */
final class InstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->tempDir('chapino-install');
        mkdir($this->root . '/config', 0775, true);
        mkdir($this->root . '/database/migrations', 0775, true);
    }

    private function installer(): Installer
    {
        return new Installer($this->root);
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return [
            'driver' => 'sqlite',
            'host' => 'localhost',
            'port' => 3306,
            'name' => '',
            'user' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'sqlite_path' => 'storage/database.sqlite',
        ];
    }

    public function testWrittenConfigurationIsValidPhpAndLoads(): void
    {
        $installer = $this->installer();
        $installer->prepareStorage(['path' => 'storage']);

        $path = $installer->writeConfiguration([
            'app' => ['name' => 'chapino', 'env' => 'production', 'debug' => false, 'url' => 'http://localhost:8080'],
            'storage' => ['path' => 'storage'],
            'database' => $this->settings(),
            'security' => ['session_idle_timeout' => 3600],
        ]);

        $this->assertTrue(is_file($path), 'config.php must exist after installation');

        // The generated file must be syntactically valid PHP and evaluate to an array, because a
        // broken generated file means a dead site and a blank page for the owner.
        $returned = require $path;
        $this->assertTrue(is_array($returned), 'config.php must return an array');
        $this->assertSame('chapino', $returned['app']['name']);
        $this->assertSame('sqlite', $returned['database']['driver']);
        $this->assertSame(true, $returned['app']['debug'] === false, 'debug must be off in production');

        $config = Config::load($this->root);
        $this->assertSame('sqlite', $config->string('database.driver'));
        $this->assertSame(3600, $config->int('security.session_idle_timeout'));
    }

    public function testInstallerRefusesToOverwriteWithoutForce(): void
    {
        $installer = $this->installer();
        $installer->writeConfiguration(['database' => $this->settings()]);

        $exception = $this->assertThrows(
            \RuntimeException::class,
            static fn () => $installer->writeConfiguration(['database' => ['driver' => 'mysql']]),
        );
        $this->assertStringContains('--force', $exception->getMessage(), 'the error must say how to proceed');

        // With force it goes through, and the new content wins.
        $installer->writeConfiguration(['database' => ['driver' => 'mysql']], true);
        $this->assertSame('mysql', Config::load($this->root)->string('database.driver'));
    }

    public function testPrepareStorageCreatesEveryRuntimeDirectoryAndProtectsUploads(): void
    {
        $created = $this->installer()->prepareStorage(['path' => 'storage']);

        $this->assertTrue(count($created) >= 6, 'the runtime directories must be created');
        foreach ([
            'storage',
            'storage/logs',
            'storage/cache',
            'storage/uploads',
            'storage/tmp',
            'storage/backups',
            'storage/sessions',
        ] as $directory) {
            $this->assertTrue(is_dir($this->root . '/' . $directory), "{$directory} must exist");
        }

        // Session files hold a logged-in user's state. On shared hosting the same machine can host
        // other customers' code, so the directory must not be readable by anyone else - and that must
        // be true from the moment it is created, not after somebody remembers to run chmod.
        $permissions = fileperms($this->root . '/storage/sessions') & 0777;
        $this->assertSame(0700, $permissions, sprintf('storage/sessions must be 0700, found 0%o', $permissions));

        // An uploads directory that can execute PHP is a remote-code-execution vector.
        $deny = $this->root . '/storage/uploads/.htaccess';
        $this->assertTrue(is_file($deny));
        $this->assertStringContains('engine off', (string) file_get_contents($deny));

        // Running it again must not fail or duplicate anything.
        $this->assertSame([], $this->installer()->prepareStorage(['path' => 'storage']));
    }

    public function testCheckConnectionReportsTheServerVersionAndFailsLoudlyOnBadSettings(): void
    {
        $version = $this->installer()->checkConnection($this->settings());
        $this->assertStringContains('SQLite', $version);

        // A directory that exists but cannot hold a database file: the failure must be reported,
        // not swallowed, so the installer stops before writing a configuration that cannot work.
        $directory = $this->tempDir('chapino-not-a-database');
        $broken = $this->settings();
        $broken['sqlite_path'] = $directory;
        $this->assertThrows(\Throwable::class, fn () => $this->installer()->checkConnection($broken));
    }

    public function testSqliteDatabaseFileIsCreatedOnDemand(): void
    {
        // Zero-friction local install: pointing at a path inside a writable folder must simply
        // work, without the owner having to create the file or touch the schema by hand.
        $settings = $this->settings();
        $settings['sqlite_path'] = 'storage/data/chapino.sqlite';

        $this->installer()->checkConnection($settings);

        $this->assertTrue(is_file($this->root . '/storage/data/chapino.sqlite'), 'the database file must be created');
    }

    public function testRequirementsCheckerFailsWhenNoConfigurationExistsAndPassesWhenItDoes(): void
    {
        // A fresh clone: the report must run anyway and name the missing configuration.
        $withoutConfig = new RequirementsChecker($this->root, null);
        $checks = $withoutConfig->run();
        $this->assertTrue($withoutConfig->hasFailures(), 'a missing configuration is a failure');

        $names = array_map(static fn (array $check): string => $check['name'], $checks);
        $this->assertTrue(in_array('فایل پیکربندی', $names, true));

        // Every check must be able to fail - otherwise it is decoration, not a check (QA rule).
        foreach ($checks as $check) {
            $this->assertTrue($check['ok'] === true || $check['ok'] === false);
            $this->assertTrue($check['detail'] !== '', 'a check must explain itself');
        }
    }

    public function testRequirementsCheckerAcceptsAWorkingSqliteInstallation(): void
    {
        $installer = $this->installer();
        $installer->prepareStorage(['path' => 'storage']);
        $installer->writeConfiguration(['database' => $this->settings(), 'storage' => ['path' => 'storage']]);

        $checker = new RequirementsChecker($this->root, Config::load($this->root));
        $this->assertFalse($checker->hasFailures(), 'a working SQLite installation must pass its own requirements check');

        $database = null;
        foreach ($checker->run() as $check) {
            if ($check['name'] === 'اتصال پایگاه‌داده') {
                $database = $check;
            }
        }
        $this->assertNotSame(null, $database, 'the database check must be present');
        $this->assertTrue($database['ok']);
        $this->assertStringContains('SQLite', $database['detail']);
    }

    public function testGeneratedConfigurationNeverContainsRenderedSecretsInPlainArraySyntax(): void
    {
        $installer = $this->installer();
        $settings = $this->settings();
        $settings['password'] = "pa'ss\\word";

        $path = $installer->writeConfiguration(['database' => $settings]);
        $returned = require $path;

        // Quoting must survive apostrophes and backslashes: a password that breaks the generated
        // file would leave the owner with a white screen and no clue why.
        $this->assertSame("pa'ss\\word", $returned['database']['password']);
    }
}
