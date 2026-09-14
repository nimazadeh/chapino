<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Schema operations used by migrations.
 *
 * Migrations describe tables with TableDefinition; this class renders and executes the SQL for the
 * engine in use. It exists so that a migration never contains dialect-specific SQL, and so that
 * every table gets the same storage rules (engine, charset, collation) without repeating them.
 */
final class Schema
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $charset = 'utf8mb4',
        private readonly string $collation = 'utf8mb4_unicode_ci',
    ) {
    }

    /** @param callable(TableDefinition): void $definition */
    public function create(string $table, callable $definition): void
    {
        $definitionObject = new TableDefinition($table);
        $definition($definitionObject);

        foreach ($definitionObject->toStatements($this->connection->driver(), $this->charset, $this->collation) as $sql) {
            $this->connection->execute($sql);
        }
    }

    /**
     * Creates the table only when it does not exist.
     *
     * Used by the very first migration of an installation and by tests; ordinary migrations must
     * use create() so that an unexpected existing table is a visible error rather than a silent
     * skip.
     *
     * @param callable(TableDefinition): void $definition
     */
    public function createIfMissing(string $table, callable $definition): bool
    {
        if ($this->connection->tableExists($table)) {
            return false;
        }
        $this->create($table, $definition);

        return true;
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS ' . TableDefinition::quote($table));
    }

    public function hasTable(string $table): bool
    {
        return $this->connection->tableExists($table);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->connection->tables();
    }

    public function hasColumn(string $table, string $column): bool
    {
        if ($this->connection->isSqlite()) {
            $rows = $this->connection->select('PRAGMA table_info(' . TableDefinition::quote($table) . ')');
            foreach ($rows as $row) {
                if (($row['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        return $this->connection->scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        ) !== null;
    }

    public function hasIndex(string $table, string $index): bool
    {
        if ($this->connection->isSqlite()) {
            $rows = $this->connection->select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $index],
            );

            return $rows !== [];
        }

        return $this->connection->scalar(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index],
        ) !== null;
    }

    /**
     * Adds a column to an existing table.
     *
     * Kept intentionally narrow: adding a nullable or defaulted column is safe on live data;
     * changing or dropping a column is a data-destroying operation and must be written and
     * reviewed explicitly per migration (see the database rule).
     *
     * @param callable(TableDefinition): void $definition
     */
    public function addColumn(string $table, callable $definition): void
    {
        $definitionObject = new TableDefinition($table);
        $definition($definitionObject);

        foreach ($definitionObject->columnDefinitions($this->connection->driver()) as $columnSql) {
            $this->connection->execute(sprintf(
                'ALTER TABLE %s ADD COLUMN %s',
                TableDefinition::quote($table),
                $columnSql,
            ));
        }
    }
}
