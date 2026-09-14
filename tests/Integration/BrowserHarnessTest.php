<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use Tests\TestCase;

/**
 * The browser test harness page (`O-11`, option 2) and its place in the two environments it has.
 *
 * The assertions are deliberately about things a browser run cannot tell us on its own:
 *
 *   * the page exists in development, with the real token and the real scripts (a harness that tests
 *     a copy of the script proves nothing);
 *   * the page - and both script endpoints - return 404 in production, because development tooling on
 *     a live host is a deployment defect, not a permission question;
 *   * the two runners cannot drift: every fixture a case asks for must exist in the fixture file, and
 *     the bytes served over HTTP must be the bytes on disk;
 *   * nothing from the test suite is ever copied into `public/`, so no deployment can serve it.
 *
 * What a browser (or jsdom, see tools/dev/browser-tests.mjs) has to answer instead: whether the
 * assertions inside `tests/browser/cases.js` actually pass. Those run in a real DOM; this file cannot
 * and does not pretend to.
 */
final class BrowserHarnessTest extends TestCase
{
    private string $storage;
    private string $configPath;

    protected function setUp(): void
    {
        $this->storage = $this->tempDir('chapino-harness');
        $this->configPath = $this->storage . '/config.php';
        $this->writeConfig('local');
        $this->resetPhpSession();
    }

    protected function tearDown(): void
    {
        $this->resetPhpSession();
    }

    private function resetPhpSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SESSION = [];
        session_id('');
    }

    private function writeConfig(string $environment): void
    {
        file_put_contents($this->configPath, '<?php return ' . var_export([
            'app' => ['env' => $environment, 'debug' => false],
            'storage' => ['path' => $this->storage],
            'logging' => ['path' => $this->storage . '/logs', 'level' => 'debug'],
            'database' => ['driver' => 'sqlite', 'sqlite_path' => $this->storage . '/test.sqlite'],
            'security' => [
                'session_name' => 'chapino_harness_session',
                'session_save_path' => $this->storage . '/sessions',
            ],
        ], true) . ';');

        putenv('CHAPINO_CONFIG=' . $this->configPath);
    }

    private function app(): Application
    {
        return new Application(APP_ROOT);
    }

    // ---------------------------------------------------------------- development: the page must work

    public function testTheHarnessPageIsServedInDevelopmentWithItsTokenAndScripts(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/tests/browser'));

        $this->assertSame(200, $response->status);

        // The token is what the CSRF cases operate on. Without a real one the harness would test a
        // page state that does not exist in the product.
        $this->assertMatches('/name="csrf-token" content="[0-9a-f]{64}"/', $response->body);
        $this->assertSame(1, $this->sessionFiles(), 'a page with a token needs exactly one session');

        // The real base script, the shared cases and the runner - in that order, so the cases see the
        // API they assert on.
        $this->assertStringContains('assets/js/app.js', $response->body);
        $this->assertStringContains('/tests/browser/cases.js', $response->body);
        $this->assertStringContains('/tests/browser/harness.js', $response->body);

        // The report contract the runner writes into must exist on the page, or a failing case would
        // report into nothing (harness.js looks these up by id).
        foreach (['id="harness-summary"', 'id="harness-report"', 'id="harness-rerun"'] as $needle) {
            $this->assertStringContains($needle, $response->body, 'missing from the harness page: ' . $needle);
        }

        // The fixtures are included from tests/browser/, so the page and the Node runner share one copy.
        $this->assertStringContains('<template id="fixtures"', $response->body);
        $this->assertStringContains('data-fixture="form-post"', $response->body);
    }

    public function testBothScriptsAreServedAsJavaScriptAndAreTheFilesOnDisk(): void
    {
        foreach (['harness.js', 'cases.js'] as $file) {
            $response = $this->app()->handle(Request::create('GET', '/tests/browser/' . $file));

            $this->assertSame(200, $response->status, $file . ' should be served');
            $this->assertStringContains('text/javascript', $response->headers['Content-Type'] ?? '');
            $this->assertStringContains('no-store', $response->headers['Cache-Control'] ?? '', 'a cached harness reports the previous edit');
            $this->assertSame(
                (string) file_get_contents(APP_ROOT . '/tests/browser/' . $file),
                $response->body,
                $file . ' must be served byte for byte from the repository',
            );
        }
    }

    // ---------------------------------------------------------------- production: it must not exist

    public function testTheHarnessDoesNotExistInProduction(): void
    {
        $this->writeConfig('production');

        foreach (['/tests/browser', '/tests/browser/harness.js', '/tests/browser/cases.js'] as $path) {
            $response = $this->app()->handle(Request::create('GET', $path));

            $this->assertSame(404, $response->status, $path . ' must be unreachable on a live host');
            $this->assertStringNotContains('harness-summary', $response->body, 'no harness markup may leak');
            $this->assertStringNotContains('chapinoBrowserTests', $response->body, 'no cases may leak');
        }
    }

    // ---------------------------------------------------------------- the two runners cannot drift

    /**
     * Every fixture a case asks for must exist, and every fixture must be asked for.
     *
     * This is the check that keeps the browser page and the Node runner honest: they execute the same
     * two files, so a case naming a fixture that was renamed (or deleted) would fail on both - after a
     * person had already opened a browser. Here it fails in the suite, in the same second.
     */
    public function testEveryFixtureAUsedByCasesExistsAndNoFixtureIsOrphaned(): void
    {
        $cases = (string) file_get_contents(APP_ROOT . '/tests/browser/cases.js');
        $fixtures = (string) file_get_contents(APP_ROOT . '/tests/browser/fixtures.html');

        preg_match_all('/fixture\(\s*"([^"]+)"\s*\)/', $cases, $used);
        preg_match_all('/data-fixture="([^"]+)"/', $fixtures, $declared);

        $used = array_values(array_unique($used[1]));
        $declared = array_values(array_unique($declared[1]));

        $this->assertTrue(count($used) >= 10, 'the harness must exercise the documented behaviours');
        $this->assertTrue(count($declared) >= 10, 'a harness with three fixtures is not a harness');

        $this->assertSame([], array_values(array_diff($used, $declared)), 'a case requests a fixture that does not exist');
        $this->assertSame([], array_values(array_diff($declared, $used)), 'a fixture exists that no case exercises');
    }

    /**
     * Nothing from the test suite may live under `public/`.
     *
     * An Apache or Nginx document root serves files, not routes: a harness file inside `public/` would
     * be reachable on a live host no matter what the router does, and the 404 test above would still
     * pass. This is the only check that can see that.
     */
    public function testNoTestAssetIsCopiedIntoTheWebRoot(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(APP_ROOT . '/public', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if (preg_match('/(harness|cases|fixtures|test)/i', $name) === 1) {
                $offenders[] = str_replace(APP_ROOT . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'the web root must contain no test files: ' . implode(', ', $offenders));
    }

    private function sessionFiles(): int
    {
        return count(glob($this->storage . '/sessions/sess_*') ?: []);
    }
}
