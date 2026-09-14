<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Connection;
use App\Core\Database\MigrationException;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Database\TableDefinition;
use Tests\TestCase;

/**
 * Migrations must be safe to run repeatedly: on shared hosting the installer may be re-run, a
 * deployment may be interrupted, and a cron-driven update must be able to retry. "Run it twice and
 * nothing breaks" is therefore a correctness requirement, not a nicety.
 */
final class MigrationTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = $this->tempDir('chapino-migrate') . '/test.sqlite';
    }

    private function migrator(?string $path = null): Migrator
    {
        $connection = Connection::fromSettings(['driver' => 'sqlite', 'sqlite_path' => $this->databasePath], APP_ROOT);

        return new Migrator(
            $connection,
            new Schema($connection),
            $path ?? APP_ROOT . '/database/migrations',
        );
    }

    public function testRealMigrationsApplyAndCreateTheExpectedTables(): void
    {
        $applied = $this->migrator()->up();

        $this->assertCount(2, $applied, 'the repository ships two migrations at this point');
        $this->assertSame(['0001_create_identity_tables', '0002_create_operations_tables'], $applied);

        $connection = Connection::fromSettings(['driver' => 'sqlite', 'sqlite_path' => $this->databasePath], APP_ROOT);
        $tables = $connection->tables();

        foreach (['migrations', 'users', 'sessions', 'settings', 'jobs', 'rate_limits', 'audit_log'] as $expected) {
            $this->assertTrue(in_array($expected, $tables, true), "table {$expected} must exist");
        }
    }

    public function testRunningMigrationsAgainIsIdempotent(): void
    {
        $migrator = $this->migrator();
        $migrator->up();
        $second = $migrator->up();

        $this->assertCount(0, $second, 'a second run must apply nothing');

        $connection = Connection::fromSettings(['driver' => 'sqlite', 'sqlite_path' => $this->databasePath], APP_ROOT);
        $this->assertSame(
            2,
            (int) $connection->scalar('SELECT COUNT(*) FROM migrations'),
            'no duplicate rows may be recorded in the migrations table',
        );
    }

    public function testStatusReportsPendingThenNothingPendingAfterApply(): void
    {
        $migrator = $this->migrator();

        $before = $migrator->status();
        $this->assertCount(2, $before['pending']);
        $this->assertCount(0, $before['applied']);

        $migrator->up();
        $after = $migrator->status();
        $this->assertCount(0, $after['pending']);
        $this->assertCount(2, $after['applied']);
        $this->assertSame([1], array_keys($after['batches']), 'one batch was created');
    }

    public function testRollbackRemovesTheBatchAndItsTablesThenReappliesCleanly(): void
    {
        $migrator = $this->migrator();
        $migrator->up();

        $rolledBack = $migrator->down();
        $this->assertCount(2, $rolledBack, 'one batch contains both migrations');
        $this->assertSame(0, count($migrator->status()['applied']));

        $connection = Connection::fromSettings(['driver' => 'sqlite', 'sqlite_path' => $this->databasePath], APP_ROOT);
        $this->assertFalse(in_array('users', $connection->tables(), true), 'rollback must drop the tables it created');

        $reapplied = $migrator->up();
        $this->assertCount(2, $reapplied, 'migrations must apply again after a rollback');
    }

    public function testFreshDestroysAndRebuildsOnlyWhenConfirmed(): void
    {
        $migrator = $this->migrator();
        $migrator->up();

        $connection = Connection::fromSettings(['driver' => 'sqlite', 'sqlite_path' => $this->databasePath], APP_ROOT);
        $connection->insert('settings', ['key_name' => 'brand.name', 'value' => 'چاپینو', 'updated_at' => $connection->now()]);

        // An accidental destructive call must be refused outright.
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $migrator->fresh(false));

        $applied = $migrator->fresh(true);
        $this->assertCount(2, $applied);

        $connection = Connection::fromSettings(['driver' => 'sqlite', 'sqlite_path' => $this->databasePath], APP_ROOT);
        $this->assertSame(0, (int) $connection->scalar('SELECT COUNT(*) FROM settings'), '--fresh must remove data');
    }

    public function testAFailingMigrationIsReportedAndNotRecordedAsApplied(): void
    {
        $directory = $this->tempDir('chapino-broken-migrations');
        file_put_contents(
            $directory . '/0001_broken.php',
            "<?php\nuse App\\Core\\Database\\Schema;\nuse App\\Core\\Database\\Connection;\nreturn ['up' => static function (Schema \$s, Connection \$c): void { throw new \\RuntimeException('boom'); }];\n",
        );

        $migrator = $this->migrator($directory);

        $exception = $this->assertThrows(MigrationException::class, fn () => $migrator->up());
        $this->assertSame('0001_broken', $exception->migration, 'the failing migration must be named');
        $this->assertSame(0, count($migrator->status()['applied']), 'a failed migration must not be recorded');
    }

    public function testMigrationsAreDiscoveredInNumericOrder(): void
    {
        $directory = $this->tempDir('chapino-order');
        foreach (['0002_second', '0001_first', '0010_tenth'] as $name) {
            file_put_contents(
                $directory . '/' . $name . '.php',
                "<?php\nuse App\\Core\\Database\\Schema;\nuse App\\Core\\Database\\Connection;\nreturn ['up' => static function (Schema \$s, Connection \$c): void {}];\n",
            );
        }
        file_put_contents($directory . '/README.md', 'ignored');
        file_put_contents($directory . '/notes.php', '<?php // not numbered: must be ignored');

        $this->assertSame(
            ['0001_first', '0002_second', '0010_tenth'],
            $this->migrator($directory)->available(),
        );
    }

    public function testCoreSchemaEnforcesTheDecisionsItClaimsTo(): void
    {
        $this->migrator()->up();
        $db = Connection::fromSettings(['driver' => 'sqlite', 'sqlite_path' => $this->databasePath], APP_ROOT);

        // One account per mobile number: the login identity (O-6) must be unique.
        $db->insert('users', [
            'mobile' => '09121234567',
            'role' => 'user',
            'owner_type' => 'personal',
            'is_active' => true,
            'created_at' => $db->now(),
            'updated_at' => $db->now(),
        ]);
        $this->assertThrows(
            \App\Core\Database\DatabaseException::class,
            static fn () => $db->insert('users', [
                'mobile' => '09121234567',
                'role' => 'user',
                'owner_type' => 'personal',
                'is_active' => true,
                'created_at' => $db->now(),
                'updated_at' => $db->now(),
            ]),
        );

        // No password column may exist anywhere: passwords are not how this product authenticates.
        $userColumns = $db->select('PRAGMA table_info(`users`)');
        foreach ($userColumns as $column) {
            $this->assertStringNotContains('password', (string) $column['name']);
        }

        // Sessions are stored hashed, never as the raw token.
        $sessionColumns = array_map(
            static fn (array $column): string => (string) $column['name'],
            $db->select('PRAGMA table_info(`sessions`)'),
        );
        $this->assertTrue(in_array('token_hash', $sessionColumns, true));
        $this->assertFalse(in_array('token', $sessionColumns, true), 'the raw session token must not be stored');
    }

    public function testSchemaRenderingIsDialectAware(): void
    {
        $definition = new TableDefinition('samples');
        $definition->id();
        $definition->string('title', 100);
        $definition->decimal('price', 14, 2);

        $sqlite = $definition->toStatements(Connection::DRIVER_SQLITE, 'utf8mb4', 'utf8mb4_unicode_ci');
        $this->assertStringContains('INTEGER PRIMARY KEY AUTOINCREMENT', $sqlite[0]);
        $this->assertStringContains('DECIMAL(14, 2)', $sqlite[0], 'the column definition must not be broken by its comma');

        $mysql = $definition->toStatements(Connection::DRIVER_MYSQL, 'utf8mb4', 'utf8mb4_unicode_ci');
        $this->assertStringContains('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $mysql[0]);
        $this->assertStringContains('ENGINE=InnoDB', $mysql[0]);
        $this->assertStringContains('utf8mb4_unicode_ci', $mysql[0], 'Persian text needs an explicit collation');
        $this->assertStringContains('PRIMARY KEY (`id`)', $mysql[0]);
    }
}
