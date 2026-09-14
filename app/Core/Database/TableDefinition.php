<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Declarative description of one table, written once and rendered for the engine in use.
 *
 * A migration must say *what* the schema is, not in which dialect; the engine-specific SQL is
 * produced by Schema. This keeps migrations readable, reviewable, and portable between the
 * local SQLite database and the MySQL-family engine of the target host (ADR-0002).
 */
final class TableDefinition
{
    /** @var list<array{name: string, type: string, nullable: bool, default: mixed, hasDefault: bool}> */
    private array $columns = [];

    /** @var list<string> */
    private array $primaryKey = [];

    /** @var list<array{columns: list<string>, name: ?string}> */
    private array $uniqueConstraints = [];

    /** @var list<array{columns: list<string>, name: ?string}> */
    private array $indexes = [];

    /** @var list<array{columns: list<string>, table: string, references: string, onDelete: string, name: ?string}> */
    private array $foreignKeys = [];

    public function __construct(private readonly string $table)
    {
    }

    // --------------------------------------------------------------------- columns

    /** Auto-incrementing primary key. */
    public function id(string $name = 'id'): self
    {
        $this->columns[] = ['name' => $name, 'type' => 'id', 'nullable' => false, 'default' => null, 'hasDefault' => false];
        $this->primaryKey[] = $name;

        return $this;
    }

    public function string(string $name, int $length = 191): self
    {
        // 191 is the default because older MySQL versions limit index keys to 767 bytes, and a
        // utf8mb4 character costs up to 4 bytes: 191 * 4 = 764 fits, 255 would not.
        return $this->addColumn($name, 'string:' . $length);
    }

    public function text(string $name): self
    {
        return $this->addColumn($name, 'text');
    }

    public function integer(string $name): self
    {
        return $this->addColumn($name, 'integer');
    }

    public function boolean(string $name): self
    {
        return $this->addColumn($name, 'boolean');
    }

    public function dateTime(string $name): self
    {
        return $this->addColumn($name, 'datetime');
    }

    /** Signed decimal with fixed precision: money and quantities only, never floating point. */
    public function decimal(string $name, int $precision = 14, int $scale = 2): self
    {
        return $this->addColumn($name, 'decimal:' . $precision . ':' . $scale);
    }

    public function nullable(): self
    {
        $index = $this->requireLastColumn('nullable()');
        $this->columns[$index]['nullable'] = true;

        return $this;
    }

    public function default(mixed $value): self
    {
        $index = $this->requireLastColumn('default()');
        $this->columns[$index]['default'] = $value;
        $this->columns[$index]['hasDefault'] = true;

        return $this;
    }

    /**
     * A modifier applied to nothing is a silent no-op, and a silent no-op in a schema definition
     * means a column with the wrong nullability in production. It is therefore an error.
     */
    private function requireLastColumn(string $modifier): int
    {
        $index = array_key_last($this->columns);
        if ($index === null) {
            throw new \LogicException($modifier . ' must be called after a column definition.');
        }

        return $index;
    }

    private function addColumn(string $name, string $type): self
    {
        $this->columns[] = ['name' => $name, 'type' => $type, 'nullable' => false, 'default' => null, 'hasDefault' => false];

        return $this;
    }

    // ------------------------------------------------------------------ constraints

    /**
     * Declares a unique constraint.
     *
     * Called with no argument it applies to the column defined last, so that a table reads as a
     * column list: `$t->string('mobile', 20)->unique();`. Called with columns it constrains a
     * combination.
     *
     * @param string|list<string>|null $columns
     */
    public function unique(string|array|null $columns = null, ?string $name = null): self
    {
        $columns ??= [$this->lastColumnName()];
        $columns = is_string($columns) ? [$columns] : $columns;
        if ($columns === []) {
            throw new \LogicException('unique() needs a column: call it after a column definition or pass column names.');
        }

        $this->uniqueConstraints[] = ['columns' => $columns, 'name' => $name];

        return $this;
    }

    /** Name of the most recently declared column, used by fluent modifiers. */
    private function lastColumnName(): string
    {
        $index = array_key_last($this->columns);
        if ($index === null) {
            return '';
        }

        return $this->columns[$index]['name'];
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = ['columns' => (array) $columns, 'name' => $name];

        return $this;
    }

    /** @param string|list<string> $columns */
    public function foreign(string|array $columns, string $references, string $onTable, string $onDelete = 'restrict'): self
    {
        $this->foreignKeys[] = [
            'columns' => (array) $columns,
            'table' => $onTable,
            'references' => $references,
            'onDelete' => strtoupper($onDelete),
            'name' => null,
        ];

        return $this;
    }

    // ------------------------------------------------------------------- rendering

    /**
     * Column definitions only, without primary/unique/foreign constraints.
     *
     * Used when adding a column to an existing table; keeping it separate is what makes that
     * operation safe - parsing the rendered CREATE TABLE text (as an earlier draft did) breaks on
     * any column containing a comma, such as DECIMAL(14, 2).
     *
     * @return list<string>
     */
    public function columnDefinitions(string $driver): array
    {
        return array_map(fn (array $column): string => $this->renderColumn($driver, $column), $this->columns);
    }

    /** @return list<string> statements, in the order they must be executed */
    public function toStatements(string $driver, string $charset, string $collation): array
    {
        $definitions = $this->columnDefinitions($driver);

        if ($this->primaryKey !== [] && !$this->hasInlinePrimaryKey($driver)) {
            $definitions[] = 'PRIMARY KEY (' . $this->quoteList($this->primaryKey) . ')';
        }

        foreach ($this->uniqueConstraints as $unique) {
            $definitions[] = 'UNIQUE (' . $this->quoteList($unique['columns']) . ')';
        }

        foreach ($this->foreignKeys as $foreign) {
            $definitions[] = sprintf(
                'FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE RESTRICT',
                $this->quoteList($foreign['columns']),
                TableDefinition::quote($foreign['table']),
                TableDefinition::quote($foreign['references']),
                $foreign['onDelete'],
            );
        }

        $suffix = '';
        if ($driver === Connection::DRIVER_MYSQL) {
            $suffix = sprintf(' ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s', $charset, $collation);
        }

        $statements = [sprintf(
            'CREATE TABLE %s (%s)%s',
            TableDefinition::quote($this->table),
            implode(', ', $definitions),
            $suffix,
        )];

        foreach ($this->indexes as $index) {
            $name = $index['name'] ?? $this->table . '_' . implode('_', $index['columns']) . '_index';
            $statements[] = sprintf(
                'CREATE INDEX %s ON %s (%s)',
                TableDefinition::quote($name),
                TableDefinition::quote($this->table),
                $this->quoteList($index['columns']),
            );
        }

        return $statements;
    }

    /** SQLite needs AUTOINCREMENT on the single INTEGER PRIMARY KEY; MySQL does not take it. */
    private function hasInlinePrimaryKey(string $driver): bool
    {
        return $driver === Connection::DRIVER_SQLITE
            && count($this->primaryKey) === 1
            && $this->columns[0]['name'] === $this->primaryKey[0]
            && $this->columns[0]['type'] === 'id';
    }

    /** @param array{name: string, type: string, nullable: bool, default: mixed, hasDefault: bool} $column */
    private function renderColumn(string $driver, array $column): string
    {
        $type = $this->renderType($driver, $column['type']);

        if ($driver === Connection::DRIVER_SQLITE && $column['type'] === 'id' && $this->hasInlinePrimaryKey($driver)) {
            return self::quote($column['name']) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sql = self::quote($column['name']) . ' ' . $type;
        $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';

        if ($column['hasDefault']) {
            $sql .= ' DEFAULT ' . $this->renderDefault($column['default']);
        }

        return $sql;
    }

    private function renderType(string $driver, string $type): string
    {
        [$base, $argument, $second] = array_pad(explode(':', $type, 3), 3, null);

        return match ($base) {
            'id' => $driver === Connection::DRIVER_SQLITE ? 'INTEGER' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'string' => 'VARCHAR(' . (int) $argument . ')',
            'text' => 'TEXT',
            'integer' => $driver === Connection::DRIVER_SQLITE ? 'INTEGER' : 'BIGINT',
            'boolean' => $driver === Connection::DRIVER_SQLITE ? 'INTEGER' : 'TINYINT(1)',
            // SQLite has no date type; ISO-8601 text sorts and compares correctly and stays
            // human-readable in a database browser.
            'datetime' => $driver === Connection::DRIVER_SQLITE ? 'TEXT' : 'DATETIME',
            'decimal' => sprintf('DECIMAL(%d, %d)', (int) $argument, (int) $second),
            default => throw new \LogicException('Unknown column type: ' . $type),
        };
    }

    private function renderDefault(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_null($value) => 'NULL',
            default => "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }

    /** @param list<string> $columns */
    private function quoteList(array $columns): string
    {
        return implode(', ', array_map([self::class, 'quote'], $columns));
    }

    public static function quote(string $identifier): string
    {
        return Connection::quoteIdentifier($identifier);
    }
}
