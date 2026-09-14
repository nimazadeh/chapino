<?php

declare(strict_types=1);

namespace Tests;

/**
 * Minimal, dependency-free test case.
 *
 * The project ships no test framework on purpose: tests must run on a plain PHP
 * installation and on shared hosting without Composer (ADR-0002). This class provides
 * assertions and fixtures - nothing else. Tests assert behaviour, never implementation
 * details (see the QA rule).
 */
abstract class TestCase
{
    private int $assertions = 0;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    /** @internal */
    public function runTest(string $method): void
    {
        $this->assertions = 0;
        $this->setUp();
        try {
            $this->{$method}();
        } finally {
            $this->tearDown();
            $this->cleanupTemp();
        }
    }

    public function assertionsCount(): int
    {
        return $this->assertions;
    }

    // ---------------------------------------------------------------- assertions

    public function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== true) {
            $this->fail($message !== '' ? $message : 'Expected true, got ' . $this->describe($value));
        }
    }

    public function assertFalse(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== false) {
            $this->fail($message !== '' ? $message : 'Expected false, got ' . $this->describe($value));
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? $message . ' - ' : '')
                . 'Expected ' . $this->describe($expected) . ', got ' . $this->describe($actual));
        }
    }

    public function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($unexpected === $actual) {
            $this->fail(($message !== '' ? $message . ' - ' : '') . 'Did not expect ' . $this->describe($unexpected));
        }
    }

    public function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== null) {
            $this->fail(($message !== '' ? $message . ' - ' : '') . 'Expected null, got ' . $this->describe($value));
        }
    }

    public function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertions++;
        if (count($actual) !== $expected) {
            $this->fail(($message !== '' ? $message . ' - ' : '')
                . "Expected {$expected} item(s), got " . count($actual) . ': ' . $this->describe($actual));
        }
    }

    public function assertArrayHasKey(string $key, array $array, string $message = ''): void
    {
        $this->assertions++;
        if (!array_key_exists($key, $array)) {
            $this->fail(($message !== '' ? $message . ' - ' : '')
                . 'Missing key "' . $key . '" in ' . $this->describe($array));
        }
    }

    public function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            $this->fail(($message !== '' ? $message . ' - ' : '')
                . 'Expected to find "' . $needle . '" in: ' . $this->excerpt($haystack));
        }
    }

    public function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            $this->fail(($message !== '' ? $message . ' - ' : '')
                . 'Did not expect to find "' . $needle . '" in: ' . $this->excerpt($haystack));
        }
    }

    public function assertMatches(string $pattern, string $value, string $message = ''): void
    {
        $this->assertions++;
        if (preg_match($pattern, $value) !== 1) {
            $this->fail(($message !== '' ? $message . ' - ' : '')
                . 'Expected "' . $value . '" to match ' . $pattern);
        }
    }

    /** @param class-string<\Throwable> $expectedClass */
    public function assertThrows(string $expectedClass, callable $callback, string $message = ''): \Throwable
    {
        $this->assertions++;
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!($e instanceof $expectedClass)) {
                $this->fail(($message !== '' ? $message . ' - ' : '')
                    . 'Expected ' . $expectedClass . ', got ' . $e::class . ': ' . $e->getMessage());
            }

            return $e;
        }

        $this->fail(($message !== '' ? $message . ' - ' : '') . 'Expected ' . $expectedClass . ', nothing was thrown');

        return new \RuntimeException('unreachable');
    }

    public function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    // ------------------------------------------------------------------- fixtures

    /** Creates a temporary directory that is removed when the test finishes. */
    protected function tempDir(string $prefix = 'test'): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            $this->fail('Could not create temporary directory: ' . $path);
        }
        $this->tempPaths[] = $path;

        return $path;
    }

    private function cleanupTemp(): void
    {
        foreach ($this->tempPaths as $path) {
            $this->removeRecursively($path);
        }
        $this->tempPaths = [];
    }

    private function removeRecursively(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeRecursively($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"' . $value . '"',
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => 'null',
            is_scalar($value) => (string) $value,
            is_array($value) => 'array(' . count($value) . ') ' . $this->excerpt((string) json_encode($value, JSON_UNESCAPED_UNICODE)),
            default => gettype($value),
        };
    }

    private function excerpt(string $value): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return mb_strlen($value) > 300 ? mb_substr($value, 0, 300) . '…' : $value;
    }
}
