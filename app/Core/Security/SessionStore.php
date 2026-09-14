<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * The part of a session that CSRF protection needs: remembering and forgetting one value.
 *
 * This interface exists to keep the dependency honest rather than to introduce flexibility for its
 * own sake: CSRF has no business knowing about save paths, timeout policy or the database, and code
 * that only needs key/value storage should not be handed the whole session. It also lets a test
 * exercise the token logic without standing up session storage.
 */
interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;

    public function forget(string $key): void;
}
