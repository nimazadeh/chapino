<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Request;
use Tests\TestCase;

/**
 * The pages this slice adds, exercised through the real request pipeline.
 *
 * The value here is the pipeline, not the markup: a page test that builds a controller directly would
 * pass even if the route, the middleware order or the session wiring were broken - which is exactly
 * the class of defect that reaches a customer.
 */
final class PagesTest extends TestCase
{
    private string $storage;
    private string $configPath;

    protected function setUp(): void
    {
        $this->storage = $this->tempDir('chapino-pages');
        $this->configPath = $this->storage . '/config.php';
        $this->writeConfig('local');

        // A real installation has run its migrations, and the write path needs them: an anonymous
        // POST is rate limited, which is a database write. Skipping this would test the "installation
        // is incomplete" branch instead of the pages.
        $connection = Connection::fromSettings([
            'driver' => 'sqlite',
            'sqlite_path' => $this->storage . '/test.sqlite',
        ], APP_ROOT);
        (new Migrator($connection, new Schema($connection), APP_ROOT . '/database/migrations'))->up();

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
                'session_name' => 'chapino_pages_session',
                'session_save_path' => $this->storage . '/sessions',
            ],
        ], true) . ';');

        putenv('CHAPINO_CONFIG=' . $this->configPath);
    }

    private function app(): Application
    {
        return new Application(APP_ROOT);
    }

    public function testTheLandingPageIsPersianRtlAndUsesTheDesignSystem(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/'));

        $this->assertSame(200, $response->status);
        $this->assertStringContains('lang="fa"', $response->body);
        $this->assertStringContains('dir="rtl"', $response->body);
        $this->assertStringContains('assets/css/tokens.css', $response->body, 'the page must load the tokens');
        $this->assertStringContains('assets/js/app.js', $response->body);
        $this->assertStringContains('پرش به محتوای اصلی', $response->body, 'the skip link must exist');

        // The landing page has no form, so it must not start a session for every visitor.
        $this->assertSame(0, $this->sessionFiles(), 'a page without a form must not create session state');
    }

    public function testAssetUrlsFollowTheDeploymentPrefixInASubfolderInstall(): void
    {
        // The XAMPP/Laragon layout. Without the prefix every stylesheet 404s and the page renders
        // unstyled - a failure that only appears on the owner's machine, never on the developer's.
        // The request is built from superglobals here, because the deployment prefix comes from the
        // server (SCRIPT_NAME) and never from the request itself.
        $originalServer = $_SERVER;
        $originalGet = $_GET;
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/chapino/public/',
            'SCRIPT_NAME' => '/chapino/public/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $_GET = [];

        try {
            $request = Request::fromGlobals();
            $this->assertSame('/chapino/public', $request->basePath());
            $response = $this->app()->handle($request);
        } finally {
            $_SERVER = $originalServer;
            $_GET = $originalGet;
        }

        $this->assertStringContains(
            '/chapino/public/assets/css/tokens.css',
            $response->body,
            'assets must be requested through the deployment prefix',
        );
    }

    /**
     * The shell stylesheet belongs to the layout, and the order is load order: tokens, base, shell,
     * components. If a page could forget part of this, some page would eventually ship unstyled and
     * nobody would notice until a screenshot.
     */
    public function testEveryPageLoadsTheDesignSystemInLoadOrder(): void
    {
        foreach (['/', '/design-system'] as $path) {
            $response = $this->app()->handle(Request::create('GET', $path));
            $this->assertSame(200, $response->status, $path . ' should render');

            $hrefs = $this->stylesheetHrefs($response->body);
            $this->assertCount(4, $hrefs, $path . ' should load exactly the four design system sheets');

            foreach (['tokens', 'base', 'layout', 'components'] as $index => $name) {
                $this->assertStringContains(
                    'assets/css/' . $name . '.css',
                    $hrefs[$index],
                    $path . ': sheet ' . ($index + 1) . ' should be ' . $name . '.css',
                );
            }

            foreach ($hrefs as $href) {
                $this->assertMatches('/\?v=\d+$/', $href, $path . ': cached sheets must be versioned');
            }

            $this->assertStringContains('defer', $response->body, $path . ' should defer the base script');
        }
    }

    /** @return list<string> */
    private function stylesheetHrefs(string $html): array
    {
        preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $html, $matches);

        return array_values($matches[1]);
    }

    public function testTheStyleGuideIsServedInDevelopmentWithItsFormToken(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/design-system'));

        $this->assertSame(200, $response->status);

        // Every component the design system claims to own must be on the page: a component that is
        // documented but not rendered is a component nobody has looked at.
        foreach ([
            'class="btn', 'btn-secondary', 'btn-danger', 'field-input', 'field-error', 'field-help',
            'card', 'alert alert-success', 'alert alert-danger', 'badge', 'table', 'spinner',
            'empty-state', 'page-title',
        ] as $needle) {
            $this->assertStringContains($needle, $response->body, 'missing from the style guide: ' . $needle);
        }

        // The form must carry a token, or its own submit button would be refused by the CSRF layer.
        $this->assertStringContains('name="_token" value="', $response->body);
        $this->assertMatches('/name="_token" value="[0-9a-f]{64}"/', $response->body);
        $this->assertSame(1, $this->sessionFiles(), 'a page with a form needs exactly one session');
    }

    public function testTheStyleGuideDoesNotExistInProduction(): void
    {
        $this->writeConfig('production');

        $response = $this->app()->handle(Request::create('GET', '/design-system'));

        $this->assertSame(404, $response->status, 'development tooling must not be reachable on a live host');
        $this->assertStringNotContains('field-input', $response->body);
    }

    public function testSubmittingTheFormWithoutATokenIsRefusedBeforeValidation(): void
    {
        // The middleware order is the control under test: a write without a token is refused even
        // though the payload itself is valid.
        $response = $this->app()->handle(Request::create('POST', '/design-system', [], [
            'full_name' => 'نیما زاده',
            'mobile' => '09120000000',
            'quantity' => '2',
        ]));

        $this->assertSame(403, $response->status);
        $this->assertSame('csrf_failed', json_decode($response->body, true)['error']['code']);
    }

    public function testInvalidInputIsReportedInPersianAndNothingTheUserTypedIsLost(): void
    {
        $app = $this->app();
        $app->session()->start();
        $token = $app->csrf()->token();

        $response = $app->handle(Request::create('POST', '/design-system', [], [
            '_token' => $token,
            'full_name' => 'ن',
            'mobile' => '12345',
            'quantity' => '99',
        ]));

        $this->assertSame(422, $response->status, 'a refused submission must not look like a success');

        $body = $response->body;
        $this->assertStringContains('aria-invalid="true"', $body, 'the invalid field must be marked for assistive tech');
        $this->assertStringContains('id="mobile-error"', $body);
        $this->assertStringContains('شماره موبایل', $body, 'the message must be Persian and name the field');
        $this->assertStringContains('value="12345"', $body, 'the typed value must come back, not an empty field');
        $this->assertStringContains('value="ن"', $body);
    }

    public function testAValidSubmissionShowsTheSuccessStateWithPersianNumbers(): void
    {
        $app = $this->app();
        $app->session()->start();
        $token = $app->csrf()->token();

        $response = $app->handle(Request::create('POST', '/design-system', [], [
            '_token' => $token,
            'full_name' => 'نیما زاده',
            'mobile' => '09120000000',
            'quantity' => '3',
        ]));

        $this->assertSame(200, $response->status);
        $this->assertStringContains('alert-success', $response->body);
        $this->assertStringContains('data-persian-number="3"', $response->body, 'displayed numbers are Persian');
        $this->assertStringContains('dir="ltr"', $response->body, 'the mobile number stays Latin and isolated');
        // Submitted values are echoed, so they must be escaped - the form is a text sink like any other.
        $this->assertStringContains('نیما زاده', $response->body);
    }

    private function sessionFiles(): int
    {
        return count(glob($this->storage . '/sessions/sess_*') ?: []);
    }
}
