<?php

declare(strict_types=1);

namespace App\Core\Install;

use App\Core\Config;

/**
 * The installation an operator can perform from a browser, on a host with no shell.
 *
 * This is the piece `bin/install.php` cannot be for a shared host: the panel gives a file manager and
 * a browser, not a terminal. It does the same work through the same `Installer` class, in an order
 * chosen for a session that can be interrupted at any moment:
 *
 *   1. the writable directories are prepared;
 *   2. the database connection is proven;
 *   3. every migration runs;
 *   4. the default settings are applied;
 *   5. **the configuration file is written last.**
 *
 * Step 5 is the deliberate difference from the CLI. `config/config.php` existing is what disables this
 * page (`isInstalled()`), so writing it earlier would mean: one failed migration, and the installer is
 * gone while the installation is broken. Writing it last means an interrupted install leaves a
 * reachable installer and an empty-but-migrated database, which is exactly the state a second attempt
 * can finish (migrations are recorded, and the seeder is create-only).
 *
 * What it refuses to do is as important as what it does: it will not install over an existing
 * installation, it will not write anything before the database answers, and it will not proceed while
 * a required capability is missing (the report tells the operator what to change in the panel).
 */
final class WebInstaller
{
    public function __construct(
        private readonly string $appRoot,
        private readonly string $storageSetting = 'storage',
        private readonly ?RequirementsChecker $checker = null,
    ) {
    }

    /** An installation exists when a configuration file exists - by either path. */
    public function isInstalled(): bool
    {
        $configured = getenv('CHAPINO_CONFIG');
        $path = ($configured !== false && $configured !== '')
            ? $configured
            : $this->appRoot . '/config/config.php';

        return is_file($path);
    }

    /** The storage directory this installation uses (the only value the installer asks the operator to trust). */
    public function storagePath(): string
    {
        return $this->storageSetting;
    }

    public function storageDirectory(): string
    {
        return str_starts_with($this->storageSetting, '/')
            ? $this->storageSetting
            : $this->appRoot . '/' . trim($this->storageSetting, '/');
    }

    public function token(): InstallToken
    {
        return new InstallToken($this->appRoot, $this->storageSetting);
    }

    public function attempts(): AttemptLimiter
    {
        return new AttemptLimiter($this->appRoot, $this->storageSetting);
    }

    /**
     * The capability report.
     *
     * Runs against the *submitted* values when there are any, so the write probe tests the storage
     * directory that will actually be used and the database line reflects the chosen engine rather
     * than the defaults.
     *
     * @param array<string, mixed> $overrides
     * @return list<array{name: string, kind: string, ok: bool, detail: string}>
     */
    public function checks(array $overrides = []): array
    {
        return $this->checker($overrides)->run();
    }

    /** @return array{required_failed: int, optional_missing: int, facts: int, ready: bool} */
    public function summary(array $overrides = []): array
    {
        return $this->checker($overrides)->summary();
    }

    /**
     * Installs, or throws with a message an operator can act on.
     *
     * @param array<string, mixed> $input driver/host/port/name/user/password/url (+ storage_path)
     * @return array{config: string, server_version: string, storage_created: list<string>, migrations: list<string>, seeded: array{added: list<string>, kept: list<string>, owner_input: list<string>}}
     */
    public function install(array $input): array
    {
        if ($this->isInstalled()) {
            throw new InstallException(
                'این نصب از قبل انجام شده است. برای نصب دوباره، ابتدا فایل config/config.php را از پنل حذف کنید.',
                'already_installed',
            );
        }

        $databaseSettings = Installer::databaseSettings($input);
        $installer = new Installer($this->appRoot);

        // Storage first, checks second - the same order the CLI uses, and for the same reason: two of
        // the required checks (the log directory and the session directory) test directories that the
        // installer itself creates. Checking before creating them would report a fresh installation as
        // incapable and block it on its own missing prerequisites.
        try {
            $storageCreated = $installer->prepareStorage(['path' => $this->storageSetting]);
        } catch (\RuntimeException $exception) {
            // The installer's own message is already operator-readable Persian; it is wrapped so the
            // page can show it in the "installation did not happen" state instead of as an internal
            // error, with a reason code that says which kind of problem this is.
            throw new InstallException($exception->getMessage(), 'storage_not_writable');
        }

        $failed = [];
        foreach ($this->checks(['database' => $databaseSettings, 'storage' => ['path' => $this->storageSetting]]) as $check) {
            if ($check['kind'] === 'required' && !$check['ok']) {
                $failed[] = $check['name'];
            }
        }
        if ($failed !== []) {
            throw new InstallException(
                'این موارد الزامی برقرار نیست و نصب متوقف شد: ' . implode('، ', $failed)
                . ' — گزارش بالا می‌گوید هر مورد چه چیزی کم دارد.',
                'requirements_failed',
            );
        }

        $serverVersion = $installer->checkConnection($databaseSettings);
        $applied = $installer->migrate($databaseSettings);
        $seeded = $installer->seed($databaseSettings);

        $configuration = Installer::configuration($input + ['storage_path' => $this->storageSetting]);
        $path = $installer->writeConfiguration($configuration, false);

        return [
            'config' => $path,
            'server_version' => $serverVersion,
            'storage_created' => $storageCreated,
            'migrations' => $applied,
            'seeded' => $seeded,
        ];
    }

    /**
     * The checker, either the injected one (tests) or a real one that works without a configuration file.
     *
     * `RequirementsChecker` accepts a null configuration, which is what the pre-installation state
     * looks like; overriding the two paths that matter (storage, database) keeps the report honest
     * about the installation that is about to be created.
     */
    private function checker(array $overrides = []): RequirementsChecker
    {
        if ($this->checker !== null) {
            return $this->checker;
        }

        $config = Config::load($this->appRoot, array_replace_recursive([
            'storage' => ['path' => $this->storageSetting],
            'database' => ['driver' => 'sqlite'],
        ], $overrides));

        return new RequirementsChecker($this->appRoot, $config);
    }
}
