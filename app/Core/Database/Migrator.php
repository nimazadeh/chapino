<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Version-tagged, re-runnable migrations.
 *
 * Why this exists at all: the product must be installable on shared hosting **without shell
 * access** (ADR-0002), so migrations are plain PHP files executed by a CLI script or by the
 * installer - no external tool, no generated SQL kept in step by hand.
 *
 * Rules enforced here (see the database rule):
 *  - a migration that has been applied is never applied twice, so running `up` again is safe
 *  - a failing migration is reported with its name and is NOT recorded as applied
 *  - the migrations table holds one row per applied file, with the batch number
 *  - `down` runs the rollback in reverse order, so migrations can undo themselves
 *
 * Caveat stated plainly: MySQL does not support transactional DDL. A migration that fails halfway
 * on MySQL can leave a partially applied change, and the report must say so rather than pretend
 * otherwise; SQLite is transactional for DDL, which is why local runs are the ones used to prove
 * ordering behaviour.
 */
final class Migrator
{
    public const TABLE = 'migrations';

    public function __construct(
        private readonly Connection $connection,
        private readonly Schema $schema,
        private readonly string $migrationsPath,
    ) {
    }

    /** @return list<string> migration names in execution order */
    public function available(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = [];
        foreach (scandir($this->migrationsPath) ?: [] as $entry) {
            if (preg_match('/^\d{4}_[a-z0-9_]+\.php$/', $entry) === 1) {
                $files[] = $entry;
            }
        }
        sort($files);

        return array_map(static fn (string $file): string => substr($file, 0, -4), $files);
    }

    /** @return array{applied: list<string>, pending: list<string>, batches: array<int, list<string>>} */
    public function status(): array
    {
        $this->ensureRegistry();

        $rows = $this->connection->select(
            'SELECT migration, batch FROM ' . TableDefinition::quote(self::TABLE) . ' ORDER BY migration',
        );

        $applied = [];
        $batches = [];
        foreach ($rows as $row) {
            $name = (string) $row['migration'];
            $batch = (int) $row['batch'];
            $applied[] = $name;
            $batches[$batch][] = $name;
        }

        return [
            'applied' => $applied,
            'pending' => array_values(array_diff($this->available(), $applied)),
            'batches' => $batches,
        ];
    }

    /**
     * Applies every pending migration in one batch.
     *
     * @return list<string> names of the migrations that were applied in this run
     */
    public function up(): array
    {
        $this->ensureRegistry();
        $status = $this->status();
        if ($status['pending'] === []) {
            return [];
        }

        $batch = ($status['batches'] === [] ? 0 : max(array_keys($status['batches']))) + 1;
        $applied = [];

        foreach ($status['pending'] as $name) {
            $migration = $this->load($name);
            try {
                ($migration['up'])($this->schema, $this->connection);
            } catch (\Throwable $e) {
                throw new MigrationException(
                    sprintf('مهاجرت %s با خطا متوقف شد. هیچ تغییری از این مهاجرت ثبت نشد.', $name),
                    $name,
                    $e,
                );
            }

            $this->connection->insert(self::TABLE, [
                'migration' => $name,
                'batch' => $batch,
                'applied_at' => $this->connection->now(),
            ]);
            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * Rolls back the most recent batch (or several).
     *
     * @return list<string> names of the migrations that were rolled back, in execution order
     */
    public function down(int $batches = 1): array
    {
        $this->ensureRegistry();
        $status = $this->status();
        if ($status['batches'] === []) {
            return [];
        }

        $batchNumbers = array_keys($status['batches']);
        sort($batchNumbers);
        $toRollback = array_slice($batchNumbers, -max(1, $batches));

        $rolledBack = [];
        foreach (array_reverse($toRollback) as $batch) {
            foreach (array_reverse($status['batches'][$batch]) as $name) {
                $migration = $this->load($name);
                if (!isset($migration['down'])) {
                    continue;
                }
                try {
                    ($migration['down'])($this->schema, $this->connection);
                } catch (\Throwable $e) {
                    throw new MigrationException(
                        sprintf('بازگردانی مهاجرت %s با خطا متوقف شد.', $name),
                        $name,
                        $e,
                    );
                }
                $this->connection->delete(self::TABLE, ['migration' => $name]);
                $rolledBack[] = $name;
            }
        }

        return array_reverse($rolledBack);
    }

    /**
     * Drops every application table and re-applies all migrations.
     *
     * Local development and tests only: this destroys data, so the caller must pass `true`
     * explicitly and the CLI requires `--force` (see the git and database rules on destructive
     * operations).
     *
     * @return list<string> names of the migrations that were applied
     */
    public function fresh(bool $confirmDestructive): array
    {
        if (!$confirmDestructive) {
            throw new \InvalidArgumentException('fresh() destroys data; pass true to confirm.');
        }

        // Foreign keys must be switched off while tables are dropped, otherwise a drop order
        // dictated by constraints would be required; the setting is restored immediately.
        if ($this->connection->isSqlite()) {
            $this->connection->pdo()->exec('PRAGMA foreign_keys = OFF');
        } else {
            $this->connection->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        }

        try {
            foreach ($this->schema->tables() as $table) {
                $this->schema->dropIfExists($table);
            }
        } finally {
            if ($this->connection->isSqlite()) {
                $this->connection->pdo()->exec('PRAGMA foreign_keys = ON');
            } else {
                $this->connection->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }

        return $this->up();
    }

    /** @return array{up: callable, down?: callable} */
    private function load(string $name): array
    {
        $path = $this->migrationsPath . '/' . $name . '.php';
        if (!is_file($path)) {
            throw new MigrationException('فایل مهاجرت پیدا نشد: ' . $name, $name);
        }

        /** @var mixed $migration */
        $migration = require $path;

        if (!is_array($migration) || !isset($migration['up']) || !is_callable($migration['up'])) {
            throw new MigrationException(
                'فایل مهاجرت باید آرایه‌ای با کلید up (و اختیاری down) برگرداند: ' . $name,
                $name,
            );
        }

        return $migration;
    }

    private function ensureRegistry(): void
    {
        if ($this->schema->hasTable(self::TABLE)) {
            return;
        }

        $this->schema->create(self::TABLE, static function (TableDefinition $table): void {
            $table->string('migration', 191);
            $table->integer('batch');
            $table->dateTime('applied_at');
            $table->unique('migration');
            $table->index('batch');
        });
    }
}
