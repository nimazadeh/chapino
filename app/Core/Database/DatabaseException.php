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
    ];

    public function __construct(
        string $message,
        public readonly string $errorCode = 'database_error',
        public readonly ?string $sqlState = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * SQLSTATE 23000 (integrity constraint violation) covers duplicates; 23505 is the
     * PostgreSQL spelling, included so the check does not need rewriting if the engine
     * decision changes.
     */
    public function isDuplicate(): bool
    {
        return $this->sqlState !== null
            && (str_starts_with($this->sqlState, '23') || $this->sqlState === '23505');
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
}
