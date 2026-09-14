<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * A failed-attempt limit for the installer's token form.
 *
 * The application's rate limiter needs the database - the very thing this page exists to install - so
 * before the installation there is nothing to count against. This is the smallest honest substitute:
 * per-client counters in a file under `storage/`, with a window and a lockout.
 *
 * What it is not: a security boundary. It slows down guessing of a 64-hex token (which is not
 * guessable anyway) and it makes an unattended installer page boring to attack. The real boundary is
 * the token file, which only someone with panel access can read.
 *
 * The client is identified by a hash of the address, never the address itself: this file is one more
 * place where a plain IP would be a needless record of who visited.
 */
final class AttemptLimiter
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_SECONDS = 900;
    private const FILE = 'install-attempts.json';

    public function __construct(
        private readonly string $appRoot,
        private readonly string $storagePath = 'storage',
        private readonly int $maxAttempts = self::MAX_ATTEMPTS,
        private readonly int $windowSeconds = self::WINDOW_SECONDS,
    ) {
    }

    public function path(): string
    {
        $directory = str_starts_with($this->storagePath, '/')
            ? $this->storagePath
            : $this->appRoot . '/' . ltrim($this->storagePath, '/');

        return rtrim($directory, '/') . '/' . self::FILE;
    }

    /** True while this client has spent its attempts inside the window. */
    public function isLockedOut(string $client): bool
    {
        $entry = $this->entries()[$this->key($client)] ?? null;
        if (!is_array($entry)) {
            return false;
        }

        if (($entry['count'] ?? 0) < $this->maxAttempts) {
            return false;
        }

        // The lockout expires with the window rather than needing a second timestamp.
        return (time() - (int) ($entry['first'] ?? 0)) < $this->windowSeconds;
    }

    /** Seconds until the client may try again; 0 when it is not locked out. */
    public function retryAfter(string $client): int
    {
        $entry = $this->entries()[$this->key($client)] ?? null;
        if (!is_array($entry)) {
            return 0;
        }

        $remaining = $this->windowSeconds - (time() - (int) ($entry['first'] ?? 0));

        return max(0, $remaining);
    }

    public function recordFailure(string $client): void
    {
        $window = $this->windowSeconds;
        // The stored key is the hash, exactly as the readers compute it: storing the raw address while
        // looking it up hashed is a lockout that never triggers.
        $key = $this->key($client);

        $this->update(static function (array $entries) use ($key, $window): array {
            $now = time();
            $entry = $entries[$key] ?? null;

            if (!is_array($entry) || ($now - (int) ($entry['first'] ?? 0)) >= $window) {
                $entries[$key] = ['count' => 1, 'first' => $now];
            } else {
                $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
                $entries[$key] = $entry;
            }

            return $entries;
        });
    }

    public function clear(string $client): void
    {
        $key = $this->key($client);

        $this->update(static function (array $entries) use ($key): array {
            unset($entries[$key]);

            return $entries;
        });
    }

    /** @return array<string, array{count: int, first: int}> */
    private function entries(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read-modify-write under an exclusive lock: two simultaneous wrong tokens must not lose a count.
     *
     * @param callable(array<string, array{count: int, first: int}>): array<string, array{count: int, first: int}> $mutate
     */
    private function update(callable $mutate): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory)) {
            // Before the installation there may be no storage directory yet. Not being able to count
            // is a reason to continue without counting, never a reason to break the page.
            if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
                return;
            }
        }

        $handle = @fopen($this->path(), 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            $contents = (string) stream_get_contents($handle);
            $entries = json_decode($contents, true);
            $entries = is_array($entries) ? $entries : [];

            // Entries older than the window are noise; keeping them would grow the file forever.
            $now = time();
            $entries = array_filter(
                $entries,
                static fn ($entry): bool => is_array($entry) && ($now - (int) ($entry['first'] ?? 0)) < 86400,
            );

            $entries = $mutate($entries);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($entries, JSON_UNESCAPED_SLASHES));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function key(string $client): string
    {
        return substr(hash('sha256', $client . '|chapino-installer'), 0, 32);
    }
}
