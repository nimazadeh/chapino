<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * A seed file is missing, malformed, or contradictory.
 *
 * Mirrors {@see MigrationException}: the failing file is part of the exception, because "a seed
 * failed" without the file name is a bug report nobody can act on.
 */
final class SeedException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $seed, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
