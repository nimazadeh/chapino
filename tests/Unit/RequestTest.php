<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use Tests\TestCase;

final class RequestTest extends TestCase
{
    /**
     * Builds a Request from superglobals exactly as a web server would set them.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $get
     */
    private function fromGlobals(array $server, array $get = []): Request
    {
        $originalServer = $_SERVER;
        $originalGet = $_GET;
        $_SERVER = array_merge(['REMOTE_ADDR' => '203.0.113.9'], $server);
        $_GET = $get;

        try {
            return Request::fromGlobals();
        } finally {
            $_SERVER = $originalServer;
            $_GET = $originalGet;
        }
    }

    public function testSubfolderDocumentRootPrefixIsNotPartOfTheRoute(): void
    {
        // XAMPP and Laragon installations live in a subfolder: htdocs/chapino/public/index.php.
        $this->assertSame(
            '/',
            $this->fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/chapino/public/',
                'SCRIPT_NAME' => '/chapino/public/index.php',
            ])->path,
        );

        $this->assertSame(
            '/api/health',
            $this->fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/chapino/public/api/health',
                'SCRIPT_NAME' => '/chapino/public/index.php',
            ])->path,
        );

        // The PATH_INFO form a rewrite can produce, with the subfolder prefix present.
        $this->assertSame(
            '/api/health',
            $this->fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/chapino/public/index.php/api/health',
                'SCRIPT_NAME' => '/chapino/public/index.php',
            ])->path,
        );
    }

    public function testSubfolderInstallationWithoutRewritingStillRoutes(): void
    {
        $request = $this->fromGlobals(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/chapino/public/index.php?r=/api/health',
                'SCRIPT_NAME' => '/chapino/public/index.php',
            ],
            ['r' => '/api/health'],
        );

        $this->assertSame('/api/health', $request->path);
    }

    public function testQueryStringRouteCannotClimbOutOfTheRouteSpace(): void
    {
        $request = $this->fromGlobals(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/index.php?r=../../etc/passwd',
                'SCRIPT_NAME' => '/index.php',
            ],
            ['r' => '../../etc/passwd'],
        );

        $this->assertSame('/', $request->path, 'a climbing path must be refused, not normalised into a route');
    }

    public function testPathIsUnchangedWhenNoPrefixApplies(): void
    {
        // A rewrite that already removed the prefix must be trusted, not double-stripped.
        $this->assertSame(
            '/api/health',
            $this->fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/health',
                'SCRIPT_NAME' => '/index.php',
            ])->path,
        );
    }

    public function testInputPrefersBodyThenQuery(): void
    {
        $request = Request::create('POST', '/api/orders', ['page' => '2'], ['color' => 'white']);

        $this->assertSame('white', $request->input('color'));
        $this->assertSame('2', $request->input('page'), 'query values are readable through input()');
        $this->assertNull($request->input('missing'));
    }

    public function testOnlyReturnsRequestedKeysEvenWhenMissing(): void
    {
        $request = Request::create('POST', '/api/orders', [], ['color' => 'white']);
        $selected = $request->only('color', 'size');

        $this->assertSame(['color' => 'white', 'size' => null], $selected);
    }

    public function testStateChangingMethodsAreRecognised(): void
    {
        $this->assertTrue(Request::create('POST', '/x')->isStateChanging());
        $this->assertTrue(Request::create('DELETE', '/x')->isStateChanging());
        $this->assertFalse(Request::create('GET', '/x')->isStateChanging());
        $this->assertFalse(Request::create('HEAD', '/x')->isStateChanging());
    }

    /**
     * Shared hosting may have no URL rewriting. In that case the front controller is
     * reached as /index.php?r=/api/health and the application must still route correctly
     * (ADR-0002). This is the behaviour that keeps the product installable anywhere.
     */
    public function testPathCanComeFromQueryStringWhenRewritingIsUnavailable(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/index.php?r=/api/health',
            'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '203.0.113.9',
        ];
        $originalServer = $_SERVER;
        $originalGet = $_GET;
        $_SERVER = $server;
        $_GET = ['r' => '/api/health'];

        try {
            $request = Request::fromGlobals();
        } finally {
            $_SERVER = $originalServer;
            $_GET = $originalGet;
        }

        $this->assertSame('/api/health', $request->path);
        $this->assertSame('203.0.113.9', $request->clientIp);
    }

    public function testFrontControllerPrefixIsStrippedFromThePath(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/index.php/api/orders',
            'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '203.0.113.9',
        ];
        $originalServer = $_SERVER;
        $_SERVER = $server;

        try {
            $request = Request::fromGlobals();
        } finally {
            $_SERVER = $originalServer;
        }

        $this->assertSame('/api/orders', $request->path);
    }

    public function testTheDeploymentPrefixIsKnownSoLinksCanBeBuilt(): void
    {
        // Every asset URL and internal link needs this value. Getting it wrong is invisible on a
        // developer machine (document root = project) and breaks the whole interface on the
        // XAMPP/Laragon layout, where the app is served from a subfolder.
        $this->assertSame(
            '/chapino/public',
            $this->fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/chapino/public/',
                'SCRIPT_NAME' => '/chapino/public/index.php',
            ])->basePath(),
        );

        $this->assertSame(
            '',
            $this->fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/health',
                'SCRIPT_NAME' => '/index.php',
            ])->basePath(),
            'a document-root install has no prefix',
        );

        $this->assertSame(
            '',
            $this->fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'])->basePath(),
            'no SCRIPT_NAME means no prefix, never a guessed one',
        );

        // Windows hosts report the script name with backslashes; the prefix must not leak them into
        // a URL.
        $this->assertSame(
            '/shop',
            $this->fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/shop/api/health',
                'SCRIPT_NAME' => '\\shop\\index.php',
            ])->basePath(),
        );

        // A trailing slash in SCRIPT_NAME must not produce a double slash in every link.
        $this->assertSame(
            '/shop',
            $this->fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/shop/',
                'SCRIPT_NAME' => '/shop/',
            ])->basePath(),
        );
    }

    public function testInvalidRemoteAddressFallsBackSafely(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => 'not-an-ip'];
        $originalServer = $_SERVER;
        $_SERVER = $server;

        try {
            $request = Request::fromGlobals();
        } finally {
            $_SERVER = $originalServer;
        }

        $this->assertSame('0.0.0.0', $request->clientIp, 'an unparseable address must never be trusted');
    }

    public function testRequestIdIsGeneratedAndUnique(): void
    {
        $this->assertMatches('/^[0-9a-f]{16}$/', Request::create('GET', '/')->requestId);
        $this->assertNotSame(Request::create('GET', '/')->requestId, Request::create('GET', '/')->requestId);
    }

    public function testTheMeasuredBodySizeIsAvailableAndUnknownWhenNotMeasured(): void
    {
        // `null` means "not measured" and must never be read as "empty": a caller that confuses the
        // two would turn an unknown size into an assumed-safe one.
        $this->assertNull(Request::create('POST', '/x')->rawBodyLength());
        $this->assertSame(0, Request::create('POST', '/x', [], [], [], '127.0.0.1', null, 0)->rawBodyLength());
        $this->assertSame(4096, Request::create('POST', '/x', [], [], [], '127.0.0.1', null, 4096)->rawBodyLength());
    }
}
