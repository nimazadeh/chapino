<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use Tests\TestCase;

/**
 * The view layer.
 *
 * The escaping tests are the important ones: a template is the last place untrusted text touches the
 * document, and a single unescaped value is a stored-XSS hole that no amount of backend validation
 * repairs (security rule).
 */
final class ViewTest extends TestCase
{
    private string $views;

    protected function setUp(): void
    {
        $this->views = $this->tempDir('chapino-views');
        mkdir($this->views . '/layouts', 0775, true);
    }

    private function view(): View
    {
        return new View($this->views);
    }

    private function write(string $template, string $body): void
    {
        file_put_contents($this->views . '/' . $template . '.php', $body);
    }

    public function testATemplateReceivesItsDataAndReturnsHtml(): void
    {
        $this->write('greeting', '<p>سلام <?= App\Core\View::e($name) ?></p>');

        $html = $this->view()->render('greeting', ['name' => 'نیما']);

        $this->assertSame('<p>سلام نیما</p>', $html);
    }

    public function testUntrustedValuesAreEscapedInTextAndAttributeContexts(): void
    {
        $this->write('danger', '<p title="<?= App\Core\View::e($value) ?>"><?= App\Core\View::e($value) ?></p>');

        $attack = '"><script>alert(1)</script>';
        $html = $this->view()->render('danger', ['value' => $attack]);

        $this->assertStringNotContains('<script>', $html, 'a value must never become markup');
        $this->assertStringNotContains('"><script', $html, 'an attribute must not be closable by data');
        $this->assertStringContains('&lt;script&gt;', $html);
        $this->assertStringContains('&quot;', $html);
    }

    public function testATemplateCannotReadTheControllersLocalVariables(): void
    {
        $this->write('isolated', '<p><?= array_key_exists("secret", get_defined_vars()) ? "leak" : "clean" ?></p>');

        $html = $this->view()->render('isolated', []);

        $this->assertStringContains('clean', $html, 'only the data passed in may be visible');
    }

    public function testAnUnknownTemplateFailsInsteadOfRenderingNothing(): void
    {
        $this->assertThrows(
            \RuntimeException::class,
            fn () => $this->view()->render('pages/missing'),
            'a missing template must be a loud failure, never a blank page',
        );
    }

    public function testATemplateNameCannotWalkTheFilesystem(): void
    {
        foreach (['../config/config', 'layouts/../../bootstrap', 'layouts/base;rm -rf'] as $name) {
            $this->assertThrows(
                \InvalidArgumentException::class,
                fn () => $this->view()->render($name),
                $name,
            );
        }
    }

    public function testATemplateThatIncludesItselfIsRefused(): void
    {
        $this->write('loop', '<?= $view->render("loop") ?>');

        $this->assertThrows(
            \RuntimeException::class,
            fn () => $this->view()->render('loop'),
            'an accidental self-include must fail fast, not exhaust memory',
        );
    }

    public function testALayoutCanRenderAPageInsideItself(): void
    {
        $this->write('layouts/base', '<main><?= $content ?></main>');
        $this->write('page', '<h1><?= App\Core\View::e($title) ?></h1>');

        $view = $this->view();
        $html = $view->render('layouts/base', ['content' => $view->render('page', ['title' => 'صفحه'])]);

        $this->assertSame('<main><h1>صفحه</h1></main>', $html);
    }
}
