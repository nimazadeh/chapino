<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config;
use App\Core\Clock;

/**
 * The single database entry point for the whole application.
 *
 * Design rules (see the backend and database rules):
 *  - every query is parameterized; there is no API here that accepts interpolated SQL
 *  - the connection is created once and reused; MySQL connection limits on shared hosting are
 *    low, so nothing here opens a second connection
 *  - the driver is chosen by configuration: the mysql-family driver for the target host,
 *    sqlite for local development and tests, so the same code path is exercised everywhere
 *  - values are stored in UTC; timestamps are written by the caller using Clock, never by the
 *    database server's clock, so behaviour is identical across engines and hosts
 */
final class Connection
{
    public const DRIVER_MYSQL = 'mysql';
    public const DRIVER_SQLITE = 'sqlite';

    private function __construct(
        private readonly \PDO $pdo,
        private readonly string $driver,
    ) {
    }

    /** @param array<string, mixed> $settings the `database` section of the configuration */
    public static function fromSettings(array $settings, string $appRoot): self
    {
        $driver = strtolower((string) ($settings['driver'] ?? self::DRIVER_SQLITE));
        self::assertDriverAvailable($driver);

        try {
            if ($driver === self::DRIVER_SQLITE) {
                $pdo = self::connectSqlite($settings, $appRoot);
            } else {
                $pdo = self::connectMysql($settings);
            }
        } catch (\PDOException $e) {
            throw new DatabaseException(
                'اتصال به پایگاه‌داده برقرار نشد. مشخصات پایگاه‌داده در فایل پیکربندی را بررسی کنید.',
                'database_connection_failed',
                $e->getCode() === '' ? null : (string) $e->getCode(),
                $e,
            );
        }

        return new self($pdo, $driver);
    }

    /** @param array<string, mixed> $settings */
    public static function fromConfig(Config $config, string $appRoot): self
    {
        /** @var array<string, mixed> $settings */
        $settings = $config->get('database', []);

        return self::fromSettings($settings, $appRoot);
    }

    /**
     * Fails with an actionable Persian message when the PDO driver is not installed.
     *
     * Checked before any connection attempt on purpose: without this guard a missing extension
     * surfaces as a fatal error or a bare "could not find driver", which tells the person
     * installing the product nothing about what to switch on in their hosting panel.
     */
    private static function assertDriverAvailable(string $driver): void
    {
        if ($driver !== self::DRIVER_MYSQL && $driver !== self::DRIVER_SQLITE) {
            throw new DatabaseException(
                'درایور پایگاه‌داده پشتیبانی نمی‌شود: ' . $driver . ' (pdo_mysql یا pdo_sqlite را در پیکربندی انتخاب کنید)',
                'database_driver_unsupported',
            );
        }

        $extension = $driver === self::DRIVER_MYSQL ? 'pdo_mysql' : 'pdo_sqlite';
        if (!in_array($driver, \PDO::getAvailableDrivers(), true)) {
            throw new DatabaseException(
                'افزونه ' . $extension . ' روی این سرور فعال نیست. برای ادامه، آن را در تنظیمات PHP (php.ini یا پنل هاست) فعال کنید.',
                'database_driver_missing',
            );
        }
    }

    /** @param array<string, mixed> $settings */
    private static function connectSqlite(array $settings, string $appRoot): \PDO
    {
        $configured = (string) ($settings['sqlite_path'] ?? 'storage/database.sqlite');
        $path = str_starts_with($configured, '/') ? $configured : $appRoot . '/' . $configured;
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new DatabaseException(
                'پوشه پایگاه‌داده قابل ساخت نیست. دسترسی پوشه storage را بررسی کنید.',
                'database_directory_unwritable',
            );
        }
        if (is_dir($path)) {
            throw new DatabaseException(
                'مسیر پایگاه‌داده به یک پوشه اشاره می‌کند، نه به فایل. مقدار sqlite_path را در پیکربندی اصلاح کنید.',
                'database_path_is_directory',
            );
        }

        $pdo = new \PDO('sqlite:' . $path);
        // Foreign keys are OFF by default in SQLite; the application must not depend on
        // different integrity behaviour between engines.
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    /** @param array<string, mixed> $settings */
    private static function connectMysql(array $settings): \PDO
    {
        $charset = (string) ($settings['charset'] ?? 'utf8mb4');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($settings['host'] ?? 'localhost'),
            (int) ($settings['port'] ?? 3306),
            (string) ($settings['name'] ?? ''),
            $charset,
        );

        return new \PDO($dsn, (string) ($settings['user'] ?? ''), (string) ($settings['password'] ?? ''), [
            // Server-side prepared statements: real parameter binding, not client-side quoting.
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === self::DRIVER_SQLITE;
    }

    /**
     * Runs a SELECT and returns all rows.
     *
     * @param array<string|int, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $parameters = []): array
    {
        $statement = $this->run($sql, $parameters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $parameters
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $parameters = []): ?array
    {
        $statement = $this->run($sql, $parameters);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Reads a single scalar value.
     *
     * @param array<string|int, mixed> $parameters
     */
    public function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->run($sql, $parameters);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Runs a statement and returns the number of affected rows.
     *
     * @param array<string|int, mixed> $parameters
     */
    public function execute(string $sql, array $parameters = []): int
    {
        return $this->run($sql, $parameters)->rowCount();
    }

    /**
     * Inserts one row and returns the new identifier.
     *
     * The column list is built from the array keys after validating them as identifiers, and
     * every value stays a bound parameter - mass assignment is prevented by the caller passing
     * an explicit array, never the whole request.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('insert() requires at least one column.');
        }

        $columns = [];
        $placeholders = [];
        $values = [];
        foreach ($data as $column => $value) {
            $columns[] = self::quoteIdentifier((string) $column);
            $placeholders[] = '?';
            $values[] = self::normalizeValue($value);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier($table),
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        $this->run($sql, $values);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Updates rows matching a simple equality condition and returns the affected row count.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new \InvalidArgumentException('update() requires both data and a condition.');
        }

        $assignments = [];
        $values = [];
        foreach ($data as $column => $value) {
            $assignments[] = self::quoteIdentifier((string) $column) . ' = ?';
            $values[] = self::normalizeValue($value);
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = self::quoteIdentifier((string) $column) . ' = ?';
            $values[] = self::normalizeValue($value);
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::quoteIdentifier($table),
            implode(', ', $assignments),
            implode(' AND ', $conditions),
        );

        return $this->run($sql, $values)->rowCount();
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('delete() requires a condition; refusing to delete every row.');
        }

        $conditions = [];
        $values = [];
        foreach ($where as $column => $value) {
            $conditions[] = self::quoteIdentifier((string) $column) . ' = ?';
            $values[] = self::normalizeValue($value);
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            self::quoteIdentifier($table),
            implode(' AND ', $conditions),
        );

        return $this->run($sql, $values)->rowCount();
    }

    /**
     * Runs a callback inside a transaction.
     *
     * Nested calls join the outer transaction instead of starting a second one, so a service
     * can be composed without silently committing halfway.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback($this);
        }

        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function tableExists(string $table): bool
    {
        if ($this->isSqlite()) {
            $found = $this->scalar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table],
            );
        } else {
            $found = $this->scalar(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
            );
        }

        return $found !== null;
    }

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->isSqlite()) {
            $rows = $this->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            );
        } else {
            $rows = $this->select(
                'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name',
            );
        }

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function now(): string
    {
        return Clock::utcDateTime();
    }

    /** @param array<string|int, mixed> $parameters */
    private function run(string $sql, array $parameters): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if ($statement === false) {
                throw new DatabaseException(DatabaseException::userMessage(), 'database_prepare_failed');
            }
            foreach (array_values($parameters) as $index => $value) {
                $statement->bindValue($index + 1, self::normalizeValue($value), self::parameterType($value));
            }
            $statement->execute();

            return $statement;
        } catch (\PDOException $e) {
            $sqlState = is_string($e->getCode()) && $e->getCode() !== '' ? $e->getCode() : null;
            throw new DatabaseException(
                $sqlState !== null && str_starts_with($sqlState, '23')
                    ? DatabaseException::duplicateMessage()
                    : DatabaseException::userMessage(),
                $sqlState !== null && str_starts_with($sqlState, '23') ? 'database_duplicate' : 'database_error',
                $sqlState,
                $e,
            );
        }
    }

    private static function parameterType(mixed $value): int
    {
        return match (true) {
            is_int($value) => \PDO::PARAM_INT,
            is_bool($value) => \PDO::PARAM_BOOL,
            $value === null => \PDO::PARAM_NULL,
            default => \PDO::PARAM_STR,
        };
    }

    /**
     * Booleans become integers because both supported engines store them that way; NULL is kept
     * as NULL so a nullable column really is null instead of an empty string.
     */
    private static function normalizeValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    /**
     * Identifier quoting, engine aware.
     *
     * Table and column names can never be bound as parameters, so they are quoted and, at the
     * call sites above, only ever come from code - never from a request.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }
}
