<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Request;
use App\Core\Security\Csrf;
use Tests\TestCase;

/**
 * The security pipeline, exercised through the real Application container.
 *
 * These tests go through `Application::handle()` rather than calling a middleware closure directly,
 * because the ordering of the layers is part of the control: a request that is refused by CSRF must
 * never reach a controller, and a rate-limited request must be refused even when its CSRF token is
 * valid.
 */
final class SecurityMiddlewareTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = $this->tempDir('chapino-security');

        $connection = Connection::fromSettings([
            'driver' => 'sqlite',
            'sqlite_path' => $this->storage . '/test.sqlite',
        ], APP_ROOT);
        (new Migrator($connection, new Schema($connection), APP_ROOT . '/database/migrations'))->up();

        foreach ([1, 5] as $id) {
            $connection->insert('users', [
                'id' => $id,
                'mobile' => '0912000' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'role' => 'user',
                'owner_type' => 'personal',
                'is_active' => true,
                'created_at' => '2026-09-14 00:00:00',
                'updated_at' => '2026-09-14 00:00:00',
            ]);
        }

        $this->resetPhpSession();
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
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

    /** @param array<string, mixed> $security */
    private function app(array $security = []): Application
    {
        $app = new Application(APP_ROOT);
        $app->withConfig([
            'app' => ['env' => 'local', 'debug' => false],
            'storage' => ['path' => $this->storage],
            'logging' => ['path' => $this->storage . '/logs', 'level' => 'debug'],
            'database' => ['driver' => 'sqlite', 'sqlite_path' => $this->storage . '/test.sqlite'],
            'security' => array_replace_recursive([
                'session_name' => 'chapino_test_session',
                'session_save_path' => $this->storage . '/sessions',
                'rate_limits' => ['anonymous_write_per_hour' => 100],
            ], $security),
        ]);

        return $app;
    }

    public function testStateChangingRequestWithoutATokenIsRefusedBeforeTheController(): void
    {
        $response = $this->app()->handle(Request::create('POST', '/api/health'));

        $this->assertSame(403, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame('csrf_failed', $payload['error']['code']);
        $this->assertStringContains('دوباره تلاش کنید', $payload['error']['message'], 'the message must be Persian');
    }

    public function testStateChangingRequestWithTheSessionTokenReachesTheRouter(): void
    {
        $app = $this->app();
        $app->session()->start();
        $token = $app->csrf()->token();

        // /api/health exists for GET only, so a POST that gets past CSRF is answered 405 by the
        // router - which is exactly the proof that the security layer let it through.
        $response = $app->handle(Request::create('POST', '/api/health', [], [], [strtolower(Csrf::HEADER) => $token]));

        $this->assertSame(405, $response->status);
    }

    public function testCrossSiteOriginIsRefusedEvenWithAValidToken(): void
    {
        $app = $this->app();
        $app->session()->start();
        $token = $app->csrf()->token();

        $response = $app->handle(Request::create('POST', '/api/health', [], [], [
            strtolower(Csrf::HEADER) => $token,
            'origin' => 'https://evil.example',
            'host' => 'chapino.example',
        ]));

        $this->assertSame(403, $response->status);
        $this->assertSame('csrf_failed', json_decode($response->body, true)['error']['code']);
    }

    public function testAnOversizedBodyIsRefusedEvenWithoutAContentLengthHeader(): void
    {
        // A body limit that a client can bypass by omitting one header is not a limit. The measured
        // size is what actually protects the application, and it must be checked first of all: no
        // storage is touched, no token is needed, and the response says which field was too large.
        $app = $this->app();

        $response = $app->handle(Request::create(
            'POST',
            '/api/health',
            [],
            [],
            [],
            '127.0.0.1',
            null,
            600 * 1024,
        ));

        $this->assertSame(422, $response->status, 'the size guard must not wait for a header');
        $payload = json_decode($response->body, true);
        $this->assertSame('validation_failed', $payload['error']['code']);
        $this->assertTrue(isset($payload['error']['fields']['body']));
    }

    public function testABodyWithinTheLimitIsNotRefusedByTheSizeGuard(): void
    {
        // The negative control for the check above: the guard must reject only what is too large,
        // otherwise it would silently break every ordinary form submission.
        $app = $this->app();
        $app->session()->start();
        $token = $app->csrf()->token();

        $response = $app->handle(Request::create(
            'POST',
            '/api/health',
            [],
            [],
            [strtolower(Csrf::HEADER) => $token, 'content-length' => '2048'],
            '127.0.0.1',
            null,
            2048,
        ));

        $this->assertSame(405, $response->status, 'a small body must reach the router');
    }

    public function testAnonymousWritesAreRateLimitedAndTellTheClientWhenToRetry(): void
    {
        $app = $this->app(['rate_limits' => ['anonymous_write_per_hour' => 2]]);
        $app->session()->start();
        $token = $app->csrf()->token();
        $headers = [strtolower(Csrf::HEADER) => $token];

        $this->assertSame(405, $app->handle(Request::create('POST', '/api/health', [], [], $headers))->status);
        $this->assertSame(405, $app->handle(Request::create('POST', '/api/health', [], [], $headers))->status);

        $blocked = $app->handle(Request::create('POST', '/api/health', [], [], $headers));

        $this->assertSame(429, $blocked->status);
        $this->assertSame('rate_limited', json_decode($blocked->body, true)['error']['code']);
        $this->assertTrue(isset($blocked->headers['Retry-After']), 'a limited client must know when to retry');
        $this->assertTrue((int) $blocked->headers['Retry-After'] > 0);
    }

    public function testAnAuthenticatedSessionIsNotSubjectToTheAnonymousWriteLimit(): void
    {
        $app = $this->app(['rate_limits' => ['anonymous_write_per_hour' => 1]]);
        $app->session()->start();
        $app->session()->login(1, 'user');
        $token = $app->csrf()->token();
        $headers = [strtolower(Csrf::HEADER) => $token];

        for ($i = 0; $i < 3; $i++) {
            $status = $app->handle(Request::create('POST', '/api/health', [], [], $headers))->status;
            $this->assertSame(405, $status, 'a logged-in user must not be counted as an anonymous writer');
        }
    }

    public function testAnAnonymousPageViewDoesNotCreateSessionState(): void
    {
        $app = $this->app();
        $response = $app->handle(Request::create('GET', '/'));

        $this->assertSame(200, $response->status);
        $this->assertSame(
            0,
            (int) $app->database()->scalar('SELECT COUNT(*) FROM sessions'),
            'a plain page view must not cost a database row',
        );
    }

    public function testTheSessionCookieIsRecognisedOnALaterRequest(): void
    {
        $app = $this->app();
        $app->session()->start();
        $app->session()->login(5, 'user');

        // The next request arrives with the session cookie, as a browser would send it.
        $_COOKIE[$app->config()->string('security.session_name')] = (string) session_id();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';

        $sameSession = $this->app();
        $sameSession->handle(Request::create('GET', '/'));

        $this->assertTrue(
            $sameSession->session()->isAuthenticated(),
            'a session with a valid row must be recognised from its cookie',
        );
    }
}
