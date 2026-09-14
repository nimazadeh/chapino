<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Asset;
use Tests\TestCase;

/**
 * Asset URLs.
 *
 * These tests exist because both things this class does are invisible on a developer machine and
 * fatal on the owner's: a missing deployment prefix 404s every stylesheet in a subfolder install, and
 * a missing cache-busting version serves a stale interface after an update.
 */
final class AssetTest extends TestCase
{
    private function assets(): Asset
    {
        return new Asset(APP_ROOT . '/public');
    }

    public function testTheUrlCarriesTheDeploymentPrefixAndAVersion(): void
    {
        $url = $this->assets()->url('/chapino/public', 'assets/css/tokens.css');

        $this->assertStringContains('/chapino/public/assets/css/tokens.css', $url);
        $this->assertMatches('#\?v=\d+$#', $url, 'the version must be the file modification time');

        // A document-root install must not end up with a doubled or empty segment.
        $this->assertStringContains('/assets/css/base.css', $this->assets()->url('', 'assets/css/base.css'));
        $this->assertStringNotContains('//assets', $this->assets()->url('', 'assets/css/base.css'));
    }

    public function testTheVersionChangesWhenTheFileChanges(): void
    {
        $directory = $this->tempDir('chapino-asset');
        mkdir($directory . '/assets', 0775, true);
        $file = $directory . '/assets/sample.css';
        file_put_contents($file, 'a{}');
        $assets = new Asset($directory);

        $first = $assets->version('assets/sample.css');

        // Ten seconds apart: file system timestamp resolution differs between platforms, and a test
        // that races the clock would fail for the wrong reason.
        touch($file, time() + 10);

        $this->assertNotSame($first, $assets->version('assets/sample.css'), 'the version must follow the file');
    }

    public function testAMissingAssetFailsLoudlyInsteadOfReturningABrokenUrl(): void
    {
        // The alternative - returning a URL anyway - produces a silent 404 in the browser and a
        // support question instead of a failing test.
        $this->assertThrows(
            \RuntimeException::class,
            fn () => $this->assets()->url('', 'assets/css/does-not-exist.css'),
        );
    }

    public function testAnAssetPathCannotLeaveThePublicDirectory(): void
    {
        foreach (['../app/bootstrap.php', 'assets/../../etc/passwd', 'assets/css/../../config/config.php'] as $attack) {
            $this->assertThrows(
                \InvalidArgumentException::class,
                fn () => $this->assets()->path($attack),
                $attack,
            );
        }
    }
}
