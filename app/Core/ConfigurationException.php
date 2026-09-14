<?php

declare(strict_types=1);

namespace App\Core;

/** Raised when configuration is missing or invalid. User-facing message is in Persian. */
final class ConfigurationException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'config_invalid')
    {
        parent::__construct($message);
    }
}
