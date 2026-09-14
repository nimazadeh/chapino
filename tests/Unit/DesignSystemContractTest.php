<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The stylesheet contract.
 *
 * CSS has no compiler, so a typo in a custom property is silent: the declaration is dropped and the
 * element keeps whatever it inherited. These tests are the compiler the browser does not give us -
 * they check the things a reader cannot check by eye across four files:
 *
 *   - every `var(--token)` used anywhere is actually defined;
 *   - nothing is loaded from a remote host (the owner has ratified no CDN, and a shared host may have
 *     no outbound access at all);
 *   - the Persian font is self-hosted, in the three weights the interface uses;
 *   - the base script has no dependency and no build step, because neither exists at runtime (C-3, C-4).
 */
final class DesignSystemContractTest extends TestCase
{
    /** @return array<string, string> file name => contents */
    private function stylesheets(): array
    {
        $files = glob(APP_ROOT . '/public/assets/css/*.css') ?: [];
        $this->assertTrue(count($files) >= 4, 'tokens, base, layout and components must all exist');

        $contents = [];
        foreach ($files as $file) {
            $contents[basename($file)] = (string) file_get_contents($file);
        }

        return $contents;
    }

    public function testEveryCustomPropertyThatIsUsedIsDefined(): void
    {
        $stylesheets = $this->stylesheets();
        $this->assertTrue(isset($stylesheets['tokens.css']), 'tokens.css is the single source of truth');

        // Definitions: `--name:` at the start of a declaration, plus the font family/weights declared
        // in base.css (which are not custom properties but are referenced the same way).
        $defined = [];
        foreach ($stylesheets as $name => $css) {
            preg_match_all('/^\s*(--[a-z0-9-]+)\s*:/mi', $css, $matches);
            $defined = array_merge($defined, $matches[1]);
        }

        $defined = array_unique($defined);
        $this->assertTrue(count($defined) > 20, 'a design system with three tokens is not a design system');

        $undefined = [];
        foreach ($stylesheets as $name => $css) {
            preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $matches);
            foreach (array_unique($matches[1]) as $token) {
                if (!in_array($token, $defined, true)) {
                    $undefined[] = $name . ' uses ' . $token;
                }
            }
        }

        $this->assertSame(
            [],
            $undefined,
            'a var() without a definition is silently dropped by the browser: ' . implode(', ', $undefined),
        );
    }

    public function testNothingIsLoadedFromARemoteHost(): void
    {
        foreach ($this->stylesheets() as $name => $css) {
            $remote = [];
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)/i', $css, $matches);
            foreach ($matches[1] as $url) {
                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '//')) {
                    $remote[] = $url;
                }
            }

            $this->assertSame([], $remote, $name . ' must not depend on a remote host');
            $this->assertStringNotContains('@import', $css, $name . ' must not import another stylesheet');
        }
    }

    public function testTheFontIsSelfHostedInTheWeightsTheInterfaceUses(): void
    {
        $css = $this->stylesheets()['base.css'];

        foreach (['Regular' => 400, 'Medium' => 500, 'Bold' => 700] as $weight => $value) {
            $file = APP_ROOT . '/public/assets/fonts/vazirmatn/Vazirmatn-' . $weight . '.woff2';

            $this->assertTrue(is_file($file), $file . ' must be committed, not downloaded at runtime');
            $this->assertTrue(filesize($file) > 10_000, 'a truncated font file would render badly in silence');
            $this->assertStringContains('font-weight: ' . $value, $css, 'the ' . $weight . ' weight must be declared');
        }

        $this->assertStringContains('font-display: swap', $css, 'text must be readable before the font loads');

        // The licence ships with the files: an OFL font may be redistributed, but only with its licence.
        $this->assertTrue(
            is_file(APP_ROOT . '/public/assets/fonts/vazirmatn/OFL.txt'),
            'the SIL OFL licence text must be committed next to the font',
        );
    }

    public function testTheBaseScriptHasNoDependencyAndNoBuildStep(): void
    {
        $script = (string) file_get_contents(APP_ROOT . '/public/assets/js/app.js');

        $this->assertStringNotContains('import ', $script, 'no module loader exists at runtime');
        $this->assertStringNotContains('require(', $script, 'no bundler exists at runtime');
        $this->assertStringNotContains('http://', $script);
        $this->assertStringNotContains('https://', $script);

        // The one global the product exposes, so later phases can use the display helpers instead of
        // writing their own Persian numeral formatting.
        $this->assertStringContains('window.chapino', $script);
    }

    public function testArabicAndPersianNumeralsAreNotConfused(): void
    {
        // A Persian thousands separator (U+066C) and decimal separator (U+066B) are easy to swap for
        // their Arabic-Indic cousins, which look identical in most editors and wrong to a reader.
        $script = (string) file_get_contents(APP_ROOT . '/public/assets/js/app.js');

        $this->assertStringContains('U+066C', $script);
        $this->assertStringContains('U+066B', $script);
        $this->assertStringNotContains('،', $script, 'the Arabic comma is not a number separator');
    }
}
