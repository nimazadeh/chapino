<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Connection;
use App\Core\Database\Schema;
use App\Core\Database\TableDefinition;
use App\Core\Settings;
use Tests\TestCase;

/**
 * The settings store: the operator's data, as opposed to the host's (`config/config.php`).
 *
 * The tests that matter here are the boring ones. A settings store fails in two ways that cost real
 * money: it overwrites a value somebody set on purpose, or it reports a value that was never stored
 * (a default standing in for a decision). Both are covered explicitly.
 */
final class SettingsTest extends TestCase
{
    private Connection $connection;
    private Settings $settings;

    protected function setUp(): void
    {
        $this->connection = Connection::fromSettings(
            ['driver' => 'sqlite', 'sqlite_path' => $this->tempDir('chapino-settings') . '/test.sqlite'],
            APP_ROOT,
        );

        // The real shape of the table (slice 0.5's schema): key_name unique, value text, updated_at.
        (new Schema($this->connection))->create('settings', static function (TableDefinition $table): void {
            $table->string('key_name', 191);
            $table->text('value');
            $table->dateTime('updated_at');
            $table->unique('key_name');
        });

        $this->settings = new Settings($this->connection);
    }

    public function testMissingKeyReturnsTheDefaultAndAnExistingEmptyKeyDoesNot(): void
    {
        $this->settings->set('site.name', '');

        $this->assertSame(null, $this->settings->get('site.absent'), 'an absent key returns no value');
        $this->assertSame('پیش‌فرض', $this->settings->get('site.absent', 'پیش‌فرض'), 'the caller default is used');
        $this->assertSame('', $this->settings->get('site.name'), 'a stored empty value stays empty');

        // The distinction is the point: the seed marks "this is your decision" by storing an empty
        // value, so "absent" and "deliberately empty" must not collapse into one state.
        $this->assertTrue($this->settings->has('site.name'));
        $this->assertFalse($this->settings->has('site.absent'));
    }

    public function testSetCreatesThenUpdatesWithoutDuplicatingTheKey(): void
    {
        $this->settings->set('print.dpi', '300');
        $this->settings->set('print.dpi', '300');   // identical value: update affects 0 rows
        $this->settings->set('print.dpi', '600');

        $this->assertSame('600', $this->settings->get('print.dpi'));
        $this->assertSame(
            1,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM settings WHERE key_name = ?', ['print.dpi']),
            'saving the same key repeatedly must never create a second row',
        );
    }

    public function testSetIfMissingKeepsWhatTheOperatorAlreadyChose(): void
    {
        $this->assertTrue($this->settings->setIfMissing('print.dpi', '300'), 'first write inserts');
        $this->assertFalse($this->settings->setIfMissing('print.dpi', '72'), 'second write keeps the existing value');
        $this->assertSame('300', $this->settings->get('print.dpi'));
    }

    public function testTypedAccessorsRefuseToGuessFromGarbage(): void
    {
        $this->settings->set('print.dpi', '300');
        $this->settings->set('pricing.commission_percent', '');
        $this->settings->set('features.ai_enabled', '0');
        $this->settings->set('features.flag_on', 'true');
        $this->settings->set('print.dpi_broken', '300dpi');

        $this->assertSame(300, $this->settings->int('print.dpi'));
        $this->assertSame(7, $this->settings->int('pricing.commission_percent', 7), 'an empty value falls back to the default');
        $this->assertSame(72, $this->settings->int('print.dpi_broken', 72), 'a non-numeric value is not cast to a plausible number');

        $this->assertFalse($this->settings->bool('features.ai_enabled'));
        $this->assertTrue($this->settings->bool('features.flag_on'));
        $this->assertTrue($this->settings->bool('features.missing_flag', true));
    }

    public function testForgetRemovesTheKey(): void
    {
        $this->settings->set('site.name', 'چاپینو');
        $this->settings->forget('site.name');

        $this->assertFalse($this->settings->has('site.name'));
    }

    public function testKeyNamesAreValidatedLoudly(): void
    {
        foreach (['Print.DPI', 'print', 'print..dpi', 'print.dpi ', 'print.dpi-x', ''] as $bad) {
            $this->assertThrows(
                \InvalidArgumentException::class,
                fn () => $this->settings->set($bad, 'x'),
                'کلید نامعتبر باید رد شود: ' . $bad,
            );
        }

        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->settings->get('Nope'));
    }

    public function testAllReturnsEveryStoredValue(): void
    {
        $this->settings->set('site.name', 'چاپینو');
        $this->settings->set('print.dpi', '300');

        $all = $this->settings->all();

        $this->assertSame('چاپینو', $all['site.name']);
        $this->assertSame('300', $all['print.dpi']);
        $this->assertCount(2, $all);
    }

    public function testComparisonAgainstDefaultsSeparatesMissingFromChanged(): void
    {
        $this->settings->set('print.dpi', '600');            // changed by the operator
        $this->settings->set('print.product_type', 'tshirt'); // equal to the default

        $comparison = $this->settings->compareWithDefaults([
            'print.dpi' => '300',
            'print.product_type' => 'tshirt',
            'print.background' => 'transparent_png',          // not stored at all
        ]);

        // This is what the seeder report reads. Printing the default next to the word "existing"
        // would hide the operator's own decision behind the value the product would have chosen.
        $this->assertSame(['print.background'], $comparison['missing']);
        $this->assertSame(['print.product_type'], $comparison['matching']);
        $this->assertSame(['print.dpi' => '600'], $comparison['changed']);
    }

    public function testValuesSurviveAcrossInstancesAndRowsStayUtf8(): void
    {
        $this->settings->set('site.description', 'چاپ طرح روی تی‌شرت — بدون نصب نرم‌افزار');

        $reloaded = (new Settings($this->connection))->get('site.description');

        $this->assertSame('چاپ طرح روی تی‌شرت — بدون نصب نرم‌افزار', $reloaded, 'Persian text and the em dash must round-trip');
    }
}
