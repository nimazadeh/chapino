<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * Proves that whoever is using the installer controls the filesystem.
 *
 * On a shared host there is no shell, so the usual "generate a one-time token and read it from the
 * console" flow is not available. What the operator always has is the hosting panel - and with it,
 * the file manager. This class turns that into the authorisation step:
 *
 *   1. The installer creates `storage/install-token.php` containing a random 64-hex token. The token
 *      is never printed on the page: printing it would authorise whoever opens the URL, which is the
 *      mistake that leaves unfinished installations take-over-able.
 *   2. The operator reads the value through the panel's file manager and pastes it into the form.
 *      Reading the file IS the proof: an attacker who can reach the URL but not the filesystem has
 *      nothing to paste.
 *   3. The token is consumed (the file is deleted) when it has done its job, so the authorisation is
 *      single-use - and the page disables itself once the installation exists (see WebInstaller).
 *
 * Two deliberate details:
 *
 *   * The file is **PHP, not text**: if a host is ever misconfigured so that `storage/` is served,
 *     PHP returns this file's value and the browser receives an empty response instead of the token.
 *   * The file is **parsed, never executed**. `require` would run whatever is in there, and storage is
 *     exactly the directory an attacker who gets in can write to.
 */
final class InstallToken
{
    public const FILE = 'install-token.php';

    public function __construct(
        private readonly string $appRoot,
        private readonly string $storagePath = 'storage',
    ) {
    }

    /** Absolute path of the token file. */
    public function path(): string
    {
        $directory = str_starts_with($this->storagePath, '/')
            ? $this->storagePath
            : $this->appRoot . '/' . ltrim($this->storagePath, '/');

        return rtrim($directory, '/') . '/' . self::FILE;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Creates a token file if there is none, and returns the token.
     *
     * @throws InstallException when the storage directory cannot be created or written to
     */
    public function create(): string
    {
        $directory = dirname($this->path());
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InstallException(
                'پوشه storage ساخته نشد. مجوز نوشتن پوشه‌ی نصب را در پنل هاست درست کنید.',
                'storage_not_writable',
            );
        }

        $token = bin2hex(random_bytes(32));
        $contents = "<?php\n\n"
            . "// One-time token for the installer / maintenance page.\n"
            . "// Read this value through the hosting panel's file manager and paste it into the form.\n"
            . "// It is deleted as soon as it is used; never paste it into a chat, a ticket or a log.\n\n"
            . "return '" . $token . "';\n";

        if (@file_put_contents($this->path(), $contents) === false) {
            throw new InstallException(
                'نوشتن فایل توکن ممکن نشد: ' . $this->displayPath(),
                'token_not_writable',
            );
        }

        // Only the account that runs the site needs to read it.
        @chmod($this->path(), 0600);

        return $token;
    }

    /** The stored token, or '' when the file is missing or does not have the shape we wrote. */
    public function read(): string
    {
        if (!$this->exists()) {
            return '';
        }

        $contents = (string) @file_get_contents($this->path());
        if (!str_starts_with($contents, '<?php')) {
            return '';
        }

        // Every comment line is stripped, then the whole body must be exactly one return statement.
        // This accepts the file this class writes (and one a human edited in the same shape) and
        // refuses anything with an extra statement in it - a file that contains code is not a file
        // this class wrote, and there is no reason to trust its value.
        $body = trim((string) preg_replace('#^\\s*//.*$#m', '', substr($contents, 5)));
        if (preg_match("/^return\\s+'([0-9a-f]{64})';$/", $body, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    /** Constant-time comparison against the stored token. */
    public function matches(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }

        $expected = $this->read();

        return $expected !== '' && hash_equals($expected, $candidate);
    }

    /** Deletes the token: authorisation is single-use. */
    public function consume(): void
    {
        if ($this->exists()) {
            @unlink($this->path());
        }
    }

    /** The path as an operator sees it: relative to the installation. */
    public function displayPath(): string
    {
        return str_replace($this->appRoot . '/', '', $this->path());
    }
}
