<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\Connection;
use App\Core\Database\TableDefinition;

/**
 * Operator-editable settings, stored in the database.
 *
 * Why a separate store instead of more entries in `config/config.php`: the configuration file holds
 * what the *host* decides (database credentials, paths, environment) and is written once by the
 * installer, while settings hold what the *operator* decides (site name, print contract values,
 * abuse quotas) and must be changeable from a panel without touching a file. Mixing the two would
 * mean editing a credentials file to rename the site.
 *
 * Values are stored as text on purpose: the column is one shape for every setting, and the typed
 * accessors (`int`, `bool`) are the single place that interprets them. A "boolean" column that
 * SQLite stores as 0/1 and MySQL as tinyint would be a second interpretation waiting to disagree.
 *
 * Every key is namespaced and lower-case (`print.dpi`, `auth.otp.ttl_seconds`), enforced by
 * {@see assertKey()}: a key that cannot be typed by hand is a key the operator cannot fix at 2am.
 */
final class Settings
{
    public const TABLE = 'settings';

    /** @var array<string, string>|null per-request cache; null means "not loaded yet" */
    private ?array $cache = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, string> every setting, keyed by name
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = $this->connection->select(
            'SELECT key_name, value FROM ' . TableDefinition::quote(self::TABLE),
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['key_name']] = (string) $row['value'];
        }

        return $this->cache = $settings;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        self::assertKey($key);

        return $this->all()[$key] ?? $default;
    }

    /**
     * Reads an integer setting.
     *
     * A value that is not an integer is reported as the default rather than silently cast to 0:
     * `(int) '300dpi'` is 300, which would turn a typo into a plausible-looking print resolution.
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        if ($value === null || preg_match('/^-?\d+$/', trim($value)) !== 1) {
            return $default;
        }

        return (int) trim($value);
    }

    /** Accepts 1/true/yes/on (case-insensitive) as true, anything else as false. */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * True when the key exists, even with an empty value.
     *
     * An empty value is meaningful here: the seed marks values that need an owner decision by
     * creating the key with an empty value, so "not set" and "set to nothing yet" must stay
     * distinguishable - otherwise every run of the seeder would look like the first one.
     */
    public function has(string $key): bool
    {
        self::assertKey($key);

        return array_key_exists($key, $this->all());
    }

    /** Creates or replaces a value. */
    public function set(string $key, string $value): void
    {
        self::assertKey($key);

        $now = Clock::nowIso();
        $affected = $this->connection->update(
            self::TABLE,
            ['value' => $value, 'updated_at' => $now],
            ['key_name' => $key],
        );

        // SQLite and MySQL both report 0 affected rows when the stored value is already identical,
        // so "0 affected" cannot be read as "no such row". The existence check is what makes the
        // difference; without it, re-saving the same value would try to insert a duplicate key.
        if ($affected === 0 && !$this->has($key)) {
            $this->connection->insert(self::TABLE, [
                'key_name' => $key,
                'value' => $value,
                'updated_at' => $now,
            ]);
        }

        $this->cache = null;
    }

    /**
     * Inserts a value only when the key is absent.
     *
     * This is the operation the seeder uses, and the reason it can be re-run safely: an operator's
     * edited value is never replaced by a default.
     *
     * @return bool true when the value was written, false when an existing value was kept
     */
    public function setIfMissing(string $key, string $value): bool
    {
        self::assertKey($key);

        if ($this->has($key)) {
            return false;
        }

        $this->connection->insert(self::TABLE, [
            'key_name' => $key,
            'value' => $value,
            'updated_at' => Clock::nowIso(),
        ]);
        $this->cache = null;

        return true;
    }

    public function forget(string $key): void
    {
        self::assertKey($key);
        $this->connection->delete(self::TABLE, ['key_name' => $key]);
        $this->cache = null;
    }

    /**
     * Compares what is stored with a set of defaults.
     *
     * Written for the seeder's report, after a real defect: the report printed the DEFAULTS from the
     * seed file next to the word "existing", so an operator who had changed a value saw the old one -
     * a report that lies about the installation it is describing.
     *
     * @param array<string, string> $defaults key => default value
     * @return array{missing: list<string>, matching: list<string>, changed: array<string, string>}
     *         `changed` maps the key to the STORED value, which is the value that matters
     */
    public function compareWithDefaults(array $defaults): array
    {
        $missing = [];
        $matching = [];
        $changed = [];

        foreach ($defaults as $key => $default) {
            $stored = $this->get($key);

            if ($stored === null) {
                $missing[] = $key;
            } elseif ($stored === $default) {
                $matching[] = $key;
            } else {
                $changed[$key] = $stored;
            }
        }

        return ['missing' => $missing, 'matching' => $matching, 'changed' => $changed];
    }

    /** Drops the in-memory cache, e.g. after another process changed the table. */
    public function refresh(): void
    {
        $this->cache = null;
    }

    /**
     * Refuses a key that is not a lower-case dotted namespace.
     *
     * Loud on purpose: a mistyped key would otherwise be created, never read, and never missed.
     */
    public static function assertKey(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $key) !== 1) {
            throw new \InvalidArgumentException(
                'کلید تنظیمات نامعتبر است: "' . $key . '" — قالب درست: نام‌فضا.نام (حروف کوچک، عدد، زیرخط)',
            );
        }
    }
}
