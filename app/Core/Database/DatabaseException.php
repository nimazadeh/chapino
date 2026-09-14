<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * A database failure.
 *
 * Carries a safe, Persian message for the user plus a machine-readable code, and keeps the
 * SQL/details in the log only (see the security rule: never leak internals to a client).
 *
 * `isDuplicate()` exists because a unique-constraint violation is a normal business event
 * ("this mobile number is already registered"), not a server fault - callers must be able to
 * recognise it without parsing driver text.
 */
final class DatabaseException extends \RuntimeException
{
    /**
     * Failures that mean "this installation is not configured correctly yet", as opposed to
     * "something went wrong while serving a request".
     *
     * The distinction matters to the person installing the product: a missing pdo_mysql extension
     * deserves a 503 page that says which switch to flip, while a failed query deserves a generic
     * 500 that leaks nothing (see the security rule).
     */
    public const SETUP_CODES = [
        'database_driver_missing',
        'database_driver_unsupported',
        'database_connection_failed',
        'database_directory_unwritable',
        'database_path_is_directory',
        'database_schema_missing',
    ];

    /**
     * Driver error codes for constraint failures, per engine.
     *
     * SQLSTATE class 23 ("integrity constraint violation") covers duplicates, foreign-key violations
     * and check violations alike, so SQLSTATE alone cannot answer "is this a duplicate?" - treating
     * the whole class as a duplicate silently swallows a foreign-key violation and turns a real bug
     * into a no-op. These codes are the specific ones:
     *   1062  MySQL/MariaDB  duplicate entry
     *   1555  SQLite         primary key constraint
     *   2067  SQLite         unique constraint
     *   23505 PostgreSQL     unique violation (kept so an engine change does not need a rewrite)
     */
    public const DUPLICATE_DRIVER_CODES = [1062, 1555, 2067];

    /**
     * 1451/1452 MySQL/MariaDB foreign key, 787/1811 SQLite foreign key, 23503 PostgreSQL.
     */
    public const FOREIGN_KEY_DRIVER_CODES = [1451, 1452, 787, 1811];

    /**
     * A table the code needs does not exist, which almost always means "migrations have not been run
     * yet" - the most likely state of a fresh upload to shared hosting, and one the operator can fix
     * in one command. Reported as a setup problem, with that command in the message.
     *
     * SQLite has no code of its own for this: code 1 is the generic "SQL logic error", so the
     * message text is the only reliable signal there and is matched narrowly.
     */
    public static function looksLikeMissingTable(?string $sqlState, ?int $driverCode, string $message): bool
    {
        if ($sqlState === '42S02' || $sqlState === '42P01') {   // MySQL "base table not found", Postgres
            return true;
        }

        if ($driverCode === 1146) {                            // MySQL "table doesn't exist"
            return true;
        }

        $haystack = strtolower($message);

        return str_contains($haystack, 'no such table')
            || str_contains($haystack, 'base table or view not found')
            || str_contains($haystack, 'does not exist');
    }

    /**
     * Every driver code that means "an integrity rule was violated", including SQLite's generic 19
     * (constraint failure) and 1299 (NOT NULL) and MySQL's 1048 (NOT NULL) and 3819 (CHECK).
     */
    public const INTEGRITY_DRIVER_CODES = [19, 787, 1048, 1062, 1299, 1451, 1452, 1555, 1811, 2067, 3819];

    public function __construct(
        string $message,
        public readonly string $errorCode = 'database_error',
        public readonly ?string $sqlState = null,
        ?\Throwable $previous = null,
        public readonly ?int $driverCode = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** A unique-constraint violation: a business event, not a server fault. */
    public function isDuplicate(): bool
    {
        return ($this->driverCode !== null && in_array($this->driverCode, self::DUPLICATE_DRIVER_CODES, true))
            || $this->sqlState === '23505';
    }

    /** A row referenced by this operation does not exist, or is still referenced. */
    public function isForeignKeyViolation(): bool
    {
        return ($this->driverCode !== null && in_array($this->driverCode, self::FOREIGN_KEY_DRIVER_CODES, true))
            || $this->sqlState === '23503';
    }

    /**
     * Any integrity-constraint failure, whatever its specific kind.
     *
     * Both signals are needed: the MySQL family reports SQLSTATE 23000, while SQLite reports HY000
     * and puts the information in the driver code.
     */
    public function isIntegrityViolation(): bool
    {
        return ($this->sqlState !== null && str_starts_with($this->sqlState, '23'))
            || ($this->driverCode !== null && in_array($this->driverCode, self::INTEGRITY_DRIVER_CODES, true));
    }

    /** True when the installation itself, not the request, is at fault. */
    public function isSetupProblem(): bool
    {
        return in_array($this->errorCode, self::SETUP_CODES, true);
    }

    public static function userMessage(): string
    {
        return 'خطا در ارتباط با پایگاه‌داده رخ داد. لطفاً کمی بعد دوباره تلاش کنید.';
    }

    public static function duplicateMessage(): string
    {
        return 'این مقدار قبلاً ثبت شده است.';
    }

    public static function foreignKeyMessage(): string
    {
        return 'این عملیات با داده‌های وابسته امکان‌پذیر نیست.';
    }

    public static function constraintMessage(): string
    {
        return 'این مقدار با قوانین پایگاه‌داده سازگار نیست.';
    }
}
