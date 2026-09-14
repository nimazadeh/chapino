<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The deployment contract: which directory may be public, and what protects the rest.
 *
 * These are the claims the install runbook makes to an operator, and every one of them is invisible
 * from the application code - a missing deny file, or a copy of the database inside the web root,
 * produces a working site and a silent leak. There is no request to test here: the subject is the
 * layout of the files on disk and the server configuration files that ship with them.
 */
final class DeploymentContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = APP_ROOT . '/' . $relative;
        $this->assertTrue(is_file($path), $relative . ' must exist');

        return (string) file_get_contents($path);
    }

    /**
     * The document root is `public/`. Pointing a host one level higher (htdocs/chapino instead of
     * htdocs/chapino/public) must fail closed rather than publish config/, storage/ and the sources.
     */
    public function testTheRepositoryRootRefusesToBeServed(): void
    {
        $htaccess = $this->read('.htaccess');

        // Both authorisation styles: the modern one, and the one an old shared host still uses.
        $this->assertStringContains('Require all denied', $htaccess);
        $this->assertStringContains('Deny from all', $htaccess);
        $this->assertStringContains('Options -Indexes', $htaccess);
        $this->assertStringContains('public', $htaccess, 'the why must name the real web root');
    }

    /**
     * storage/ holds sessions, logs and (in SQLite installations) the database. It belongs outside the
     * web root; when a host decides the document root, this file is the second line of defence.
     */
    public function testStorageDefendsItselfAndSurvivesAClone(): void
    {
        $htaccess = $this->read('storage/.htaccess');

        $this->assertStringContains('Require all denied', $htaccess);
        $this->assertStringContains('Deny from all', $htaccess);

        // The directory must exist in a fresh clone: without .gitkeep, git drops it and the installer
        // has to create it (it does, but a missing directory is a confusing first symptom).
        $this->assertTrue(is_file(APP_ROOT . '/storage/.gitkeep'), 'storage/ must survive a clone');
    }

    /**
     * Only the front controller may execute inside the web root. An uploaded or generated `.php` file
     * under public/ would otherwise be a second entry point into the application.
     */
    public function testTheWebRootExecutesNothingButTheFrontController(): void
    {
        $htaccess = $this->read('public/.htaccess');

        $this->assertStringContains('<FilesMatch "\\.(php|phtml|phar)$">', $htaccess);
        $this->assertStringContains('<Files "index.php">', $htaccess);
        $this->assertStringContains('<Files "install.php">', $htaccess);
        $this->assertStringContains('RewriteRule ^ index.php [L]', $htaccess, 'routes must reach the front controller');

        // The allowlist and the directory must agree. A PHP file in the web root that nobody granted
        // is a file that fails mysteriously on Apache; one that is granted without appearing here is
        // an entry point nobody reviewed. Both are caught by comparing the two lists.
        $executables = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(APP_ROOT . '/public', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (preg_match('/\.(php|phtml|phar)$/i', $file->getFilename()) === 1) {
                $executables[] = $file->getFilename();
            }
        }
        sort($executables);

        $this->assertSame(
            ['index.php', 'install.php'],
            $executables,
            'a new PHP file in the web root must be a deliberate decision, not a side effect',
        );
    }

    /**
     * A database file, a log or a `.env` inside the web root is downloadable by anyone who guesses the
     * name - and names are guessable. This walks the directory rather than trusting the file list.
     */
    public function testTheWebRootContainsNoConfigurationOrRuntimeState(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(APP_ROOT . '/public', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!preg_match('/\.(sqlite|sqlite3|db|log|env|ini|bak|orig|key|pem)$/i', $file->getFilename())) {
                continue;
            }
            $offenders[] = str_replace(APP_ROOT . '/', '', $file->getPathname());
        }

        $this->assertSame([], $offenders, 'nothing like this may live in the web root: ' . implode(', ', $offenders));
    }

    /**
     * The template that gets copied to `config/config.php` must never carry a real credential: it is
     * committed, and the runbook tells the operator to copy it before filling anything in.
     */
    public function testTheExampleConfigurationCarriesNoSecrets(): void
    {
        $example = require APP_ROOT . '/config/config.example.php';

        $this->assertTrue(is_array($example), 'the example must return a configuration array');
        $this->assertSame('', $example['sms']['api_key'] ?? 'missing', 'no SMS key in the template');
        $this->assertSame('', $example['payment']['merchant_id'] ?? 'missing', 'no merchant id in the template');
        $this->assertSame('', $example['database']['password'] ?? 'missing', 'no database password in the template');
        $this->assertSame('production', $example['app']['env'] ?? '', 'the template must not ship in debug or local mode');
        $this->assertFalse($example['app']['debug'] ?? true, 'debug must be off in the template');
    }

    /**
     * `php -S` has no rewriting, so the local development command needs a router script. It is a
     * development aid: it lives in `tools/dev/`, refuses to run under any other SAPI, and is therefore
     * never part of a deployment.
     */
    public function testTheBuiltInServerRouterIsADevelopmentHelper(): void
    {
        $router = $this->read('tools/dev/serve-router.php');

        $this->assertStringContains("PHP_SAPI !== 'cli-server'", $router, 'the router must refuse to run under Apache/nginx');
        $this->assertStringContains('return false;', $router, 'existing files must be served by the built-in server');
        $this->assertStringContains("'/../public'", $router, 'the router must serve the real web root');
        $this->assertFalse(is_file(APP_ROOT . '/public/serve-router.php'), 'the router must not be deployed');
    }
}
