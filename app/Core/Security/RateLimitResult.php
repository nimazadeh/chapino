<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * The outcome of a rate-limit decision, as data.
 *
 * Returning a result instead of a boolean exists so that a caller can tell "you have 2 attempts
 * left" from "you are blocked for 40 more seconds" without re-querying, and so the numbers can be
 * logged and observed (the security rule requires limits to be observable).
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $used,
        public readonly int $windowSeconds,
        public readonly int $retryAfterSeconds,
    ) {
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'limit' => $this->limit,
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'window_seconds' => $this->windowSeconds,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }
}
