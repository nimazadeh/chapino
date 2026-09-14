<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Database\SeedException;
use App\Core\Database\Seeder;
use App\Core\Settings;
use Tests\TestCase;

/**
 * The seeder's whole contract is one sentence: **a default is written only when the key is absent**.
 *
 * Everything else here exists to keep that sentence true - a re-run must not touch an operator's
 * value, a malformed seed file must fail loudly with its own file name, and the values that are the
 * owner's decision must stay empty instead of being filled in by a plausible-looking guess.
 *
 * `testARatifiedValueIsSeededAsDecided` repeats values from the seed file on purpose. It is the guard
 * that turns editing a ratified number (300 DPI, t-shirt, an empty commission) into a deliberate act
 * with a failing test attached, instead of a silent edit that nobody reviews.
 */
final class SeederTest extends TestCase
{
    private Connection $connection;
    private Settings $settings;

    protected function setUp(): void
    {
        $this->connection = Connection::fromSettings(
            ['driver' => 'sqlite', 'sqlite_path' => $this->tempDir('chapino-seed') . '/test.sqlite'],
            APP_ROOT,
        );

        // The real migrations, not a hand-written settings table: this proves the seed works against
        // the schema the product actually ships.
        (new Migrator(
            $this->connection,
            new Schema($this->connection),
            APP_ROOT . '/database/migrations',
        ))->up();

        $this->settings = new Settings($this->connection);
    }

    private function seeder(?string $seedsPath = null): Seeder
    {
        return new Seeder($this->settings, $seedsPath ?? APP_ROOT . '/database/seeds');
    }

    public function testAFreshInstallationGetsEveryDefaultAndASecondRunChangesNothing(): void
    {
        $seeder = $this->seeder();
        $expected = count($seeder->defaults());
        $this->assertTrue($expected > 0, 'the repository must ship at least one seed file');

        $this->assertCount($expected, $seeder->pending(), 'every default is missing before the first run');

        $first = $seeder->run();
        $this->assertCount($expected, $first['added']);
        $this->assertSame([], $first['kept']);

        $second = $seeder->run();
        $this->assertSame([], $second['added'], 'the second run adds nothing');
        $this->assertCount($expected, $second['kept']);
        $this->assertSame([], $seeder->pending());

        // The point of create-only: the database holds every key exactly once.
        $this->assertSame(
            $expected,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM settings'),
        );
    }

    public function testAnOperatorValueIsNeverOverwrittenByARun(): void
    {
        $this->seeder()->run();

        // What a panel would do, and what the seeder must respect afterwards.
        $this->settings->set('print.dpi', '600');
        $this->settings->set('print.product_type', 'hoodie');

        $result = $this->seeder()->run();

        $this->assertSame('600', $this->settings->get('print.dpi'), 'a changed value survives every later run');
        $this->assertSame('hoodie', $this->settings->get('print.product_type'));
        $this->assertSame([], $result['added'], 'nothing is re-added');
    }

    public function testDefaultsMarkedForOwnerInputStayEmptyAndAreReported(): void
    {
        $result = $this->seeder()->run();

        $this->assertTrue(
            in_array('pricing.commission_percent', $result['owner_input'], true),
            'the commission rate is an owner decision and must be reported as such',
        );
        $this->assertSame(
            '',
            $this->settings->get('pricing.commission_percent'),
            'no agent may invent a money value; the key stays empty until the owner decides',
        );
    }

    public function testARatifiedValueIsSeededAsDecided(): void
    {
        $this->seeder()->run();

        // O-14 (print contract), ratified 2026-09-14.
        $this->assertSame('tshirt', $this->settings->get('print.product_type'));
        $this->assertSame(300, $this->settings->int('print.dpi'));
        $this->assertSame(30, $this->settings->int('print.area.front.width_cm'));
        $this->assertSame(40, $this->settings->int('print.area.front.height_cm'));
        $this->assertSame('transparent_png', $this->settings->get('print.background'));
        $this->assertSame('rgb', $this->settings->get('print.color_space'));

        // O-6 (mobile + one-time code, no passwords) and C-13 (the AI entry point is visible).
        $this->assertSame('mobile_otp', $this->settings->get('auth.method'));
        $this->assertTrue($this->settings->bool('features.ai_button_visible'));
    }

    public function testEveryDefaultIsAValidNamespacedKeyWithAValueAndANote(): void
    {
        foreach ($this->seeder()->defaults() as $key => $definition) {
            $this->assertMatches('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $key, 'key format: ' . $key);
            $this->assertTrue($definition['note'] !== '', 'every default explains itself: ' . $key);
            $this->assertTrue(
                $definition['value'] !== '' || $definition['owner_input'],
                'a default is either a real value or explicitly the owner\'s decision: ' . $key,
            );
            $this->assertSame(
                '0001_default_settings',
                $definition['source'],
                'defaults come from the committed seed file',
            );
            $this->assertTrue(
                is_file(APP_ROOT . '/database/seeds/' . $definition['source'] . '.php'),
                'the seed file named by the definition must exist: ' . $definition['source'],
            );
        }
    }

    public function testTheProvidersAndSecretsAreNotSeededIntoTheDatabase(): void
    {
        $this->seeder()->run();

        // Credentials and provider choices live in config/config.php next to the secrets. A copy in
        // the database would be a second source of truth that drifts on the first deployment.
        foreach (array_keys($this->settings->all()) as $key) {
            $this->assertStringNotContains('api_key', $key, 'a secret must never be seeded: ' . $key);
            $this->assertStringNotContains('merchant', $key, 'a secret must never be seeded: ' . $key);
        }

        $this->assertFalse($this->settings->has('payments.gateway'), 'the gateway is configured in config/config.php');
        $this->assertFalse($this->settings->has('sms.provider'), 'the SMS provider is configured in config/config.php');
    }

    public function testAMalformedSeedFileFailsLoudlyWithItsOwnName(): void
    {
        $path = $this->tempDir('chapino-badseed');
        $cases = [
            'badkey' => "<?php return ['Not A Key' => ['value' => 'x']];",
            'novalue' => "<?php return ['site.name' => 'chapino'];",
            'notarray' => "<?php return 'chapino';",
        ];

        foreach ($cases as $name => $content) {
            $directory = $path . '/' . $name;
            mkdir($directory, 0775, true);
            file_put_contents($directory . '/0001_broken.php', $content);

            $exception = $this->assertThrows(
                SeedException::class,
                fn () => $this->seeder($directory)->defaults(),
                'a malformed seed file must fail loudly: ' . $name,
            );
            $this->assertSame('0001_broken', $exception->seed, 'the failing file names itself');
        }
    }

    public function testTheSameKeyInTwoSeedFilesIsRefusedInsteadOfSilentlyOverriding(): void
    {
        $path = $this->tempDir('chapino-dupseed');
        file_put_contents($path . '/0001_first.php', "<?php return ['site.name' => ['value' => 'a']];");
        file_put_contents($path . '/0002_second.php', "<?php return ['site.name' => ['value' => 'b']];");

        $exception = $this->assertThrows(
            SeedException::class,
            fn () => $this->seeder($path)->defaults(),
            'a key defined twice must be refused',
        );
        $this->assertStringContains('site.name', $exception->getMessage());
    }
}
