<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * A migration failed or is malformed.
 *
 * Carries the migration name so the report and the console output name exactly which change stopped,
 * which is the difference between a five-minute fix and an afternoon of guessing.
 */
final class MigrationException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $migration, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
