<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * The session could not be started (unwritable save path, output already sent, storage failure).
 *
 * This is deliberately an exception and not a silent fallback. A request that runs without sessions
 * still answers 200, but its CSRF token cannot be remembered and its login state cannot be trusted -
 * a silent downgrade of a security control is worse than a visible failure (see the security rule).
 * The error handler maps this to 503 with an actionable Persian message.
 */
final class SessionException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'session_unavailable', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
