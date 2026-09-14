<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Install\RequirementsChecker;
use App\Core\Settings;
use Tests\TestCase;

/**
 * The capability report (`O-2`, `O-3`, `O-20`).
 *
 * A report nobody trusts is worse than no report, so the assertions here are about what the report
 * *claims*, not just that it runs:
 *
 *   - it says "ready" on an installation that is ready, and not ready when a requirement is missing;
 *   - it never leaves anything behind (the write probe is the only thing it creates);
 *   - the ratified requirements (`O-3`: curl and zip included) cannot quietly disappear;
 *   - the cron line is evidence-based: it changes when a heartbeat exists and says so when it does not.
 */
final class RequirementsCheckerTest extends TestCase
{
    private string $root;
    private string $databasePath;

    protected function setUp(): void
    {
        $this->root = $this->tempDir('chapino-requirements');
        $this->databasePath = $this->root . '/storage/database.sqlite';

        mkdir($this->root . '/config', 0775, true);
        mkdir($this->root . '/storage/logs', 0775, true);
        mkdir($this->root . '/storage/sessions', 0700, true);
        mkdir($this->root . '/bin', 0775, true);
        file_put_contents($this->root . '/bin/cron.php', "<?php // placeholder for the report's cron line\n");
    }

    private function writeConfig(): void
    {
        file_put_contents($this->root . '/config/config.php', '<?php return ' . var_export([
            'app' => ['name' => 'chapino', 'env' => 'local', 'debug' => false, 'url' => 'http://localhost:8080'],
            'storage' => ['path' => 'storage'],
            'database' => ['driver' => 'sqlite', 'sqlite_path' => 'storage/database.sqlite'],
            'security' => ['session_save_path' => 'storage/sessions'],
            'logging' => ['level' => 'debug', 'path' => 'storage/logs'],
        ], true) . ';');
    }

    /**
     * Loads the configuration from THIS test's directory, without clearing CHAPINO_CONFIG first.
     *
     * That is deliberate: this line is the canary for environment leakage between tests. If a test
     * that sets CHAPINO_CONFIG ever stops restoring it, `Config::load()` here reads that other test's
     * fixture path and every check below fails - which is how the leak was found in the first place.
     */
    private function checker(): RequirementsChecker
    {
        return new RequirementsChecker($this->root, Config::load($this->root));
    }

    public function testEveryEntryHasAKnownKindAndOnlyFactsAreInformational(): void
    {
        $this->writeConfig();
        $checks = $this->checker()->run();

        $this->assertTrue(count($checks) > 10, 'the report covers PHP, extensions, paths, database and facts');

        $kinds = [];
        foreach ($checks as $check) {
            $kinds[$check['kind']] = ($kinds[$check['kind']] ?? 0) + 1;
            $this->assertTrue($check['name'] !== '', 'every entry is named');
            $this->assertTrue($check['detail'] !== '', 'every entry explains itself: ' . $check['name']);
            $this->assertTrue(in_array($check['kind'], ['required', 'optional', 'fact'], true), 'kind: ' . $check['kind']);
        }

        $this->assertTrue(($kinds['required'] ?? 0) > 0);
        $this->assertTrue(($kinds['optional'] ?? 0) > 0);
        $this->assertTrue(($kinds['fact'] ?? 0) > 5, 'the facts are what fills O-20');
    }

    public function testTheRatifiedPhpAndExtensionRequirementsArePinned(): void
    {
        $this->writeConfig();

        $byName = [];
        foreach ($this->checker()->run() as $check) {
            $byName[$check['name']] = $check;
        }

        $this->assertArrayHasKey('نسخه PHP', $byName);
        $this->assertTrue($byName['نسخه PHP']['ok'], 'this environment runs a supported PHP');

        // O-3 names mbstring, pdo, json, curl, openssl, zip. Dropping one of these from the report
        // would silently allow an installation where payments or uploads cannot work.
        foreach (['pdo', 'mbstring', 'json', 'curl', 'openssl', 'zip'] as $extension) {
            $this->assertArrayHasKey('افزونه ' . $extension, $byName, 'the report must name ' . $extension);
            $this->assertSame('required', $byName['افزونه ' . $extension]['kind'], $extension . ' is a ratified requirement (O-3)');
        }
    }

    public function testAReadyInstallationIsReportedAsReady(): void
    {
        $this->writeConfig();
        $checker = $this->checker();

        $summary = $checker->summary();

        $this->assertFalse($checker->hasFailures(), 'nothing required may fail on a prepared installation');
        $this->assertTrue($summary['ready']);
        $this->assertSame(0, $summary['required_failures']);
    }

    public function testAMissingConfigurationIsAFailureNotAWarning(): void
    {
        // No config/config.php: the state of every fresh upload.
        $checker = new RequirementsChecker($this->root, null);

        $this->assertTrue($checker->hasFailures(), 'an installation without configuration is not ready');
        $this->assertFalse($checker->summary()['ready']);
    }

    public function testAnUnwritableSessionDirectoryIsReportedBecauseLoginWouldFailOnTheHost(): void
    {
        $this->writeConfig();
        // A session path that does not exist, which is exactly what a mis-typed configuration gives.
        file_put_contents($this->root . '/config/config.php', str_replace(
            "'storage/sessions'",
            "'storage/no-such-sessions'",
            (string) file_get_contents($this->root . '/config/config.php'),
        ));

        $checker = $this->checker();
        $sessionCheck = null;
        foreach ($checker->run() as $check) {
            if ($check['name'] === 'پوشه‌ی ذخیره‌ی نشست‌ها') {
                $sessionCheck = $check;
            }
        }

        $this->assertNotSame(null, $sessionCheck, 'the report must contain a session directory check');
        $this->assertFalse($sessionCheck['ok'], 'a missing session directory is a real failure, not a warning');
        $this->assertSame('required', $sessionCheck['kind']);
        $this->assertTrue($checker->hasFailures());
    }

    public function testTheWriteProbeLeavesNothingBehind(): void
    {
        $this->writeConfig();
        $this->checker()->run();
        $this->checker()->run();

        $leftovers = glob($this->root . '/storage/.requirements-*.tmp') ?: [];
        $this->assertSame([], $leftovers, 'the checker must clean up its own probe files');
    }

    public function testTheCronLineIsEvidenceFromTheLastRealRun(): void
    {
        $this->writeConfig();

        $before = $this->fact($this->checker(), 'وضعیت کران (شاهد اجرا)');
        $this->assertStringContains('هنوز هیچ اجرایی ثبت نشده', $before, 'with no heartbeat the report says so');

        // The settings table has to exist before a heartbeat can be written, and it comes from the
        // product's own migrations - so this test also proves the report reads a real installation.
        $connection = Connection::fromSettings(
            ['driver' => 'sqlite', 'sqlite_path' => 'storage/database.sqlite'],
            $this->root,
        );
        (new Migrator($connection, new Schema($connection), APP_ROOT . '/database/migrations'))->up();

        // Exactly what bin/cron.php writes at the end of a run.
        $settings = new Settings($connection);
        $settings->set('cron.last_run_at', '2026-09-14T10:00:00Z');
        $settings->set('cron.last_run_jobs', '3');

        $after = $this->fact($this->checker(), 'وضعیت کران (شاهد اجرا)');
        $this->assertStringContains('2026-09-14T10:00:00Z', $after, 'the report shows the last run it can see');
        $this->assertStringContains('3', $after, 'and how many jobs that run took');
        $this->assertTrue(
            $this->checker()->summary()['ready'],
            'a missing heartbeat is a fact, not a failure: a fresh installation has not run cron yet',
        );
    }

    public function testFactsAreExposedAsNameValuePairsForRecording(): void
    {
        $this->writeConfig();
        $facts = $this->checker()->facts();

        foreach (['محیط اجرا', 'درایورهای PDO موجود', 'محدودیت‌های اجرا', 'فضای دیسک', 'زبان و زمان', 'وضعیت کران (شاهد اجرا)'] as $name) {
            $this->assertArrayHasKey($name, $facts, 'the record must include: ' . $name);
        }

        $this->assertStringContains('SAPI=', $facts['محیط اجرا']);
        $this->assertStringContains('memory_limit=', $facts['محدودیت‌های اجرا']);
    }

    private function fact(RequirementsChecker $checker, string $name): string
    {
        foreach ($checker->run() as $check) {
            if ($check['name'] === $name) {
                return $check['detail'];
            }
        }

        $this->fail('the report has no entry named: ' . $name);

        return '';
    }
}
