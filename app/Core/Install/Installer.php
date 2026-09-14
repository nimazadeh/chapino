<?php

declare(strict_types=1);

namespace App\Core\Install;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Database\Seeder;
use App\Core\Settings;

/**
 * Writes the configuration and prepares the database, without shell access and without Composer.
 *
 * The installer is deliberately separable from the CLI script that drives it, so the same logic can
 * be tested and, later, reused by the web installer that a host without shell access needs
 * (ADR-0002). It refuses to overwrite an existing configuration unless explicitly told to, because
 * silently replacing a working installation's settings is a destructive act (see the git rule's
 * spirit: never destroy work that already exists).
 */
final class Installer
{
    public function __construct(private readonly string $appRoot)
    {
    }

    /**
     * Writes config/config.php from the given settings.
     *
     * @param array<string, mixed> $settings the `database` section, plus optional `app` overrides
     * @return string the path that was written
     */
    public function writeConfiguration(array $settings, bool $overwrite = false): string
    {
        $path = $this->appRoot . '/config/config.php';

        if (is_file($path) && !$overwrite) {
            throw new \RuntimeException(
                'فایل پیکربندی از قبل وجود دارد. برای بازنویسی، گزینه --force را اضافه کنید.',
            );
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('پوشه config قابل ساخت نیست: ' . $directory);
        }

        $contents = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "/**\n"
            . " * Created by bin/install.php on " . Clock::nowIso() . " (UTC).\n"
            . " * Contains credentials: never commit this file, never paste its contents into a chat,\n"
            . " * an issue or a log (see the security rule).\n"
            . " */\n\n"
            . 'return ' . $this->exportArray($settings, 0) . ";\n";

        if (@file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('نوشتن فایل پیکربندی ممکن نشد: ' . $path);
        }

        // Configured files should not be world-readable if the host allows restricting them.
        @chmod($path, 0640);

        return $path;
    }

    /** Verifies that the given database settings actually connect. */
    public function checkConnection(array $databaseSettings): string
    {
        $connection = Connection::fromSettings($databaseSettings, $this->appRoot);

        return $connection->isSqlite()
            ? 'SQLite ' . (string) $connection->scalar('SELECT sqlite_version()')
            : (string) $connection->scalar('SELECT VERSION()');
    }

    /**
     * Creates the writable runtime directories the application expects.
     *
     * @return list<string> directories that were created
     */
    public function prepareStorage(array $storageSettings): array
    {
        $root = $this->appRoot . '/' . ltrim((string) ($storageSettings['path'] ?? 'storage'), '/');
        $created = [];

        foreach (['', '/logs', '/cache', '/uploads', '/tmp', '/backups', '/sessions'] as $sub) {
            $path = $root . $sub;
            if (!is_dir($path)) {
                if (!@mkdir($path, 0775, true) && !is_dir($path)) {
                    throw new \RuntimeException('ساخت پوشه ممکن نشد: ' . $path);
                }
                // Session files carry bearer secrets: only the application user may read the directory.
                // It is created with 0700 and tightened explicitly, because umask on shared hosting
                // often makes a new directory world-readable.
                if ($sub === '/sessions') {
                    @chmod($path, 0700);
                }
                $created[] = $path;
            }
        }

        // Apache 2.2 hosts ignore .htaccess in some configurations; a deny file is added anyway
        // because the uploads directory must never serve executable content (see the security rule).
        $denyFile = $root . '/uploads/.htaccess';
        if (!is_file($denyFile)) {
            @chmod($denyFile, 0644);
            @file_put_contents($denyFile, "php_flag engine off\n<FilesMatch \".*\\.(php|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");
        }

        return $created;
    }

    /**
     * Runs every pending migration against the given settings.
     *
     * @param array<string, mixed> $databaseSettings
     * @return list<string> applied migration names
     */
    public function migrate(array $databaseSettings): array
    {
        $connection = Connection::fromSettings($databaseSettings, $this->appRoot);
        $schema = new Schema(
            $connection,
            (string) ($databaseSettings['charset'] ?? 'utf8mb4'),
            (string) ($databaseSettings['collation'] ?? 'utf8mb4_unicode_ci'),
        );

        $migrator = new Migrator($connection, $schema, $this->appRoot . '/database/migrations');

        return $migrator->up();
    }

    /**
     * Applies the default settings after migrating.
     *
     * Kept separate from `migrate()` on purpose: migrations change the schema and are recorded,
     * seeds fill in values and are create-only, so an operator can re-run one without the other.
     * The installer runs both because a fresh installation needs both.
     *
     * @param array<string, mixed> $databaseSettings
     * @return array{added: list<string>, kept: list<string>, owner_input: list<string>}
     */
    public function seed(array $databaseSettings): array
    {
        $connection = Connection::fromSettings($databaseSettings, $this->appRoot);

        return (new Seeder(new Settings($connection), $this->appRoot . '/database/seeds'))->run();
    }

    /**
     * Renders a nested array as PHP source with short array syntax.
     *
     * Uses var_export per scalar rather than json_encode so that PHP types survive the round trip,
     * and indents for a file a human will actually open and edit.
     *
     * @param array<string, mixed> $values
     */
    private function exportArray(array $values, int $depth): string
    {
        $indent = str_repeat('    ', $depth);
        $inner = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($values as $key => $value) {
            $renderedKey = is_int($key) ? (string) $key : "'" . str_replace("'", "\\'", (string) $key) . "'";
            if (is_array($value)) {
                $lines[] = $inner . $renderedKey . ' => ' . $this->exportArray($value, $depth + 1) . ',';
            } else {
                $lines[] = $inner . $renderedKey . ' => ' . var_export($value, true) . ',';
            }
        }

        return "[\n" . implode("\n", $lines) . "\n" . $indent . ']';
    }
}
