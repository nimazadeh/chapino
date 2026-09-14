<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * A failure the operator standing in front of the installer can act on.
 *
 * Separate from the generic exceptions because the web installer must show the message to a human
 * (in Persian, next to the field that caused it) while never showing a stack trace or a path it does
 * not need to show. The code is stable and machine-readable so the page can decide what to highlight.
 */
final class InstallException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'install_failed')
    {
        parent::__construct($message);
    }
}
