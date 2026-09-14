<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Structured, append-only file logger (JSON lines).
 *
 * File based on purpose: shared hosting offers no log daemon and no in-memory store
 * (ADR-0002). Never log secrets, tokens or personal data - see the security rule.
 */
final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    public function __construct(
        private readonly string $directory,
        private readonly string $minimumLevel = 'info',
    ) {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }
        if (self::LEVELS[$level] < (self::LEVELS[$this->minimumLevel] ?? 20)) {
            return;
        }

        $line = json_encode(
            [
                'time' => Clock::nowIso(),
                'level' => $level,
                'message' => $message,
                'context' => Redactor::clean($context),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $file = $this->directory . '/app-' . gmdate('Y-m-d', Clock::now()) . '.log';
        // LOCK_EX keeps lines intact when two requests log at the same moment.
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
