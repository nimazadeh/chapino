<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Connection;
use App\Core\Database\DatabaseException;
use App\Core\Database\Schema;
use App\Core\Database\TableDefinition;
use Tests\TestCase;

/**
 * The data layer, exercised against a real database engine (SQLite locally; the same code runs
 * against the MySQL-family engine on the host).
 *
 * Persian text round-tripping is a first-class test here, not a detail: a platform for Iran that
 * mangles Persian on the way to storage is broken regardless of how clean the SQL looks.
 */
final class DatabaseTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = $this->tempDir('chapino-db') . '/test.sqlite';
    }

    private function connection(): Connection
    {
        return Connection::fromSettings([
            'driver' => 'sqlite',
            'sqlite_path' => $this->databasePath,
        ], APP_ROOT);
    }

    public function testConnectAndRoundTripPersianText(): void
    {
        $db = $this->connection();
        $schema = new Schema($db);
        $schema->create('samples', static function (TableDefinition $table): void {
            $table->id();
            $table->string('title', 191);
            $table->text('body');
            $table->dateTime('created_at');
        });

        $id = $db->insert('samples', [
            'title' => 'طرح تیشرت «بهار»',
            'body' => "متن دوسطری\nبا نیم‌فاصله و ۱۲۳ رقم فارسی",
            'created_at' => $db->now(),
        ]);

        $row = $db->first('SELECT title, body FROM samples WHERE id = ?', [$id]);

        $this->assertNotSame(null, $row);
        $this->assertSame('طرح تیشرت «بهار»', $row['title']);
        $this->assertStringContains('نیم‌فاصله', (string) $row['body'], 'the half-space must survive storage');
        $this->assertStringContains('۱۲۳', (string) $row['body'], 'Persian digits must survive storage');
    }

    public function testUniqueConstraintIsReportedAsADuplicateNotAServerError(): void
    {
        $db = $this->connection();
        $schema = new Schema($db);
        $schema->create('accounts', static function (TableDefinition $table): void {
            $table->id();
            $table->string('mobile', 20)->unique();
        });

        $db->insert('accounts', ['mobile' => '09121234567']);

        $exception = $this->assertThrows(
            DatabaseException::class,
            static fn () => $db->insert('accounts', ['mobile' => '09121234567']),
        );

        $this->assertTrue($exception->isDuplicate(), 'a duplicate must be recognisable as a business event');
        $this->assertSame('database_duplicate', $exception->errorCode);
    }

    public function testTransactionRollsBackCompletelyOnFailure(): void
    {
        $db = $this->connection();
        $schema = new Schema($db);
        $schema->create('orders', static function (TableDefinition $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
        });

        try {
            $db->transaction(static function (Connection $db): void {
                $db->insert('orders', ['reference' => 'A-1']);
                $db->insert('orders', ['reference' => 'A-1']); // violates the unique constraint
            });
            $this->fail('The duplicate insert should have failed');
        } catch (DatabaseException $e) {
            $this->assertTrue($e->isDuplicate());
        }

        $count = $db->scalar('SELECT COUNT(*) FROM orders');
        $this->assertSame(0, (int) $count, 'a failed transaction must leave nothing behind');
    }

    public function testForeignKeysAreEnforced(): void
    {
        $db = $this->connection();
        $schema = new Schema($db);
        $schema->create('parents', static function (TableDefinition $table): void {
            $table->id();
            $table->string('name', 50);
        });
        $schema->create('children', static function (TableDefinition $table): void {
            $table->id();
            $table->integer('parent_id');
            $table->foreign('parent_id', 'id', 'parents', 'cascade');
        });

        // A child pointing at a non-existent parent must be rejected: integrity belongs in the
        // database, so an application bug cannot corrupt the data (database rule).
        $this->assertThrows(
            DatabaseException::class,
            static fn () => $db->insert('children', ['parent_id' => 999]),
        );

        $parentId = $db->insert('parents', ['name' => 'والد']);
        $childId = $db->insert('children', ['parent_id' => $parentId]);
        $this->assertSame(1, (int) $childId);

        $db->delete('parents', ['id' => $parentId]);
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM children'), 'cascade delete must apply');
    }

    public function testNullValuesStayNullInsteadOfBecomingEmptyStrings(): void
    {
        $db = $this->connection();
        $schema = new Schema($db);
        $schema->create('nullable_samples', static function (TableDefinition $table): void {
            $table->id();
            $table->string('note', 50)->nullable();
        });

        $id = $db->insert('nullable_samples', ['note' => null]);
        $row = $db->first('SELECT note FROM nullable_samples WHERE id = ?', [$id]);

        $this->assertNotSame(null, $row);
        $this->assertNull($row['note'], 'NULL must stay NULL: an empty string is a different fact');
    }

    public function testBooleansAreStoredAsIntegersAndReadBack(): void
    {
        $db = $this->connection();
        $schema = new Schema($db);
        $schema->create('flags', static function (TableDefinition $table): void {
            $table->id();
            $table->boolean('is_active')->default(true);
        });

        $db->insert('flags', ['is_active' => false]);
        $db->insert('flags', ['is_active' => true]);

        $this->assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM flags WHERE is_active = 0'));
        $this->assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM flags WHERE is_active = 1'));
    }

    public function testUnsafeIdentifierIsRejectedInsteadOfInterpolated(): void
    {
        $db = $this->connection();

        $this->assertThrows(
            \InvalidArgumentException::class,
            static fn () => $db->insert('users; DROP TABLE users', ['x' => 1]),
        );
    }

    public function testDeletingWithoutAConditionIsRefused(): void
    {
        $db = $this->connection();
        $schema = new Schema($db);
        $schema->create('rows_sample', static function (TableDefinition $table): void {
            $table->id();
            $table->string('name', 20)->nullable();
        });
        $db->insert('rows_sample', ['name' => 'نمونه']);

        // An accidental "delete everything" must be impossible by construction.
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $db->delete('rows_sample', []));
    }
}
