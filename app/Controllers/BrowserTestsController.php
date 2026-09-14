<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * The browser test harness (`O-11`, option 2: a harness that ships with the repository).
 *
 * Why it lives behind a route instead of a loose HTML file in `public/`:
 *
 *  1. **It must run with the real page around it.** The harness loads the same `app.js`, the same
 *     stylesheets and the same `Asset` versioning as every product page, so a failure means the real
 *     script is broken - not that a copy of it was broken.
 *  2. **It must not exist on a live installation.** The same guard as the style guide: in production
 *     this returns 404, not 403, because a page that says "you are not allowed" also says "this page
 *     exists".
 *  3. **It must not need an installation.** No database, no configuration values: the harness only
 *     needs a browser. It can therefore be opened on a fresh clone to answer "does the base script
 *     still work?" before anything is configured.
 *
 * The files it serves live in `tests/browser/`, outside the web root, which is also why the script
 * and the cases are served through this controller rather than as static assets: nothing from the
 * test suite is ever copied into `public/`.
 */
final class BrowserTestsController
{
    public function __construct(private readonly Application $app)
    {
    }

    /** GET /tests/browser - the harness page. */
    public function page(Request $request): Response
    {
        $this->refuseInProduction();

        $view = $this->app->view();
        // Asking for the token starts the session. The harness checks the token behaviour on real
        // form elements, so a page without one would be a page that cannot test what it exists for.
        $csrfToken = $this->app->csrf()->token();

        $html = $view->render('layouts/base', [
            'title' => 'تست مرورگر',
            'base' => $request->basePath(),
            'csrfToken' => $csrfToken,
            'content' => $view->render('tests/browser', [
                'base' => $request->basePath(),
                'fixturesPath' => $this->app->root() . '/tests/browser/fixtures.html',
                'harnessScript' => $this->assetUrl($request, 'harness.js'),
                'casesScript' => $this->assetUrl($request, 'cases.js'),
            ]),
        ]);

        return Response::html($html);
    }

    /** GET /tests/browser/harness.js - the runner. */
    public function harness(Request $request): Response
    {
        return $this->script('harness.js');
    }

    /** GET /tests/browser/cases.js - the shared cases, identical to the ones the Node runner executes. */
    public function cases(Request $request): Response
    {
        return $this->script('cases.js');
    }

    private function script(string $file): Response
    {
        $this->refuseInProduction();

        $path = $this->app->root() . '/tests/browser/' . $file;
        if (!is_file($path)) {
            throw HttpException::notFound();
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', 'text/javascript; charset=utf-8')
            // Development tooling: always the file on disk, never a cached copy. A harness that
            // reports the state of the previous edit is worse than no harness.
            ->withHeader('Cache-Control', 'no-store');
    }

    private function assetUrl(Request $request, string $file): string
    {
        $path = $this->app->root() . '/tests/browser/' . $file;
        $version = is_file($path) ? (string) filemtime($path) : '0';

        return $request->basePath() . '/tests/browser/' . $file . '?v=' . $version;
    }

    /**
     * Development tooling must not exist on a live installation - 404, never 403 (see the class doc).
     */
    private function refuseInProduction(): void
    {
        if ($this->app->config()->string('app.env', 'production') === 'production') {
            throw HttpException::notFound();
        }
    }
}
