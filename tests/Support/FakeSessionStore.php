<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Security\SessionStore;

/**
 * A session store that lives in memory, used to test code that only needs to remember a value.
 *
 * This is a test double, not a weakened assertion: it implements the same contract the real session
 * implements, and the tests that care about real session behaviour (storage, regeneration, timeouts)
 * use the real `Session` against a real database.
 */
final class FakeSessionStore implements SessionStore
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }
}
