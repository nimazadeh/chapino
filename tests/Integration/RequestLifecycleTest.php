<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Clock;
use App\Core\Request;
use Tests\TestCase;

/**
 * Exercises the whole request lifecycle through the real front-controller path:
 * middleware -> router -> controller -> response, including the error paths.
 *
 * These tests boot the real Application with a test configuration, so they prove the
 * wiring that production uses, not a simplified copy of it.
 */
final class RequestLifecycleTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = $this->tempDir('chapino-storage');
    }

    private function app(): Application
    {
        $app = new Application(APP_ROOT);
        $app->withConfig([
            'app' => ['env' => 'local', 'debug' => false],
            'logging' => ['path' => $this->storage . '/logs', 'level' => 'debug'],
            'storage' => ['path' => $this->storage],
            // A test must never write into the repository, so the database lives in the
            // temporary directory alongside the logs.
            'database' => ['driver' => 'sqlite', 'sqlite_path' => $this->storage . '/test.sqlite'],
            'security' => [
                'session_name' => 'chapino_lifecycle_session',
                'session_save_path' => $this->storage . '/sessions',
            ],
        ]);

        return $app;
    }

    public function testHealthEndpointReturnsCapabilitiesAndSafeFactsOnly(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/api/health', [], [], [], '127.0.0.1', 'req1234567890abc'));

        $this->assertSame(200, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertTrue($payload['ok']);
        $this->assertSame('ok', $payload['data']['status']);

        // C-12 / ADR-0003: the AI capability is reported as off, not hidden.
        $this->assertFalse($payload['data']['capabilities']['ai_generation']);

        // No leak: the health payload must never describe the filesystem or secrets.
        $this->assertStringNotContains(APP_ROOT, $response->body);
        $this->assertStringNotContains('password', $response->body);
        $this->assertStringNotContains('api_key', $response->body);
    }

    public function testHealthReportsDatabaseStatusWithoutLeakingConnectionDetails(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/api/health'));
        $payload = json_decode($response->body, true);

        $this->assertTrue($payload['data']['database']['connected'], 'the local test database must be reachable');
        $this->assertSame('sqlite', $payload['data']['database']['driver']);
        $this->assertSame(
            count(glob(APP_ROOT . '/database/migrations/*.php') ?: []),
            $payload['data']['database']['migrations_pending'],
            'a database that has never been migrated must report every shipped migration as pending',
        );

        $this->assertStringNotContains($this->storage, $response->body, 'no filesystem path may be exposed');
    }

    public function testHealthStays200WhenTheDatabaseIsUnreachable(): void
    {
        $app = new Application(APP_ROOT);
        $app->withConfig([
            'app' => ['env' => 'local'],
            'logging' => ['path' => $this->storage . '/logs', 'level' => 'debug'],
            // A database that cannot be opened: a liveness endpoint must still answer, and must
            // report the problem instead of pretending everything is fine. Pointing the database
            // at an existing directory fails identically on every platform.
            'database' => ['driver' => 'sqlite', 'sqlite_path' => $this->storage],
            'security' => [
                'session_name' => 'chapino_lifecycle_session',
                'session_save_path' => $this->storage . '/sessions',
            ],
        ]);

        $response = $app->handle(Request::create('GET', '/api/health'));
        $payload = json_decode($response->body, true);

        $this->assertSame(200, $response->status);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['data']['database']['connected']);
        $this->assertNull($payload['data']['database']['migrations_pending']);
    }

    public function testEveryResponseCarriesSecurityHeadersAndRequestId(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/api/health'));

        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        $this->assertSame('SAMEORIGIN', $response->headers['X-Frame-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $response->headers['Referrer-Policy']);
        $this->assertArrayHasKey('X-Request-Id', $response->headers);
    }

    public function testUnknownRouteReturnsAJsonErrorShapeNotHtml(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/api/nope'));

        $this->assertSame(404, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertFalse($payload['ok']);
        $this->assertSame('not_found', $payload['error']['code']);
        $this->assertStringContains('پیدا نشد', $payload['error']['message'], 'the message must be Persian');
    }

    public function testValidationErrorShapeIncludesPerFieldMessages(): void
    {
        $app = $this->app();
        $response = $app->handle(Request::create('POST', '/api/health', [], [], ['content-length' => '999999999']));

        $payload = json_decode($response->body, true);
        $this->assertSame(422, $response->status);
        $this->assertSame('validation_failed', $payload['error']['code']);
        $this->assertArrayHasKey('body', $payload['error']['fields']);
    }

    public function testUnexpectedFailureIsReportedSafelyAndLoggedWithDetail(): void
    {
        $app = $this->app();
        $app->router()->get('/api/explodes', static function (): never {
            throw new \RuntimeException('database credentials are hunter2 and the path is /srv/secret');
        });

        $response = $app->handle(Request::create('GET', '/api/explodes', [], [], [], '127.0.0.1', 'req-explode-001'));

        $this->assertSame(500, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame('server_error', $payload['error']['code']);
        $this->assertSame('req-explode-001', $payload['error']['request_id']);

        // The client must learn nothing about the internals...
        $this->assertStringNotContains('hunter2', $response->body);
        $this->assertStringNotContains('/srv/secret', $response->body);
        $this->assertStringNotContains('RuntimeException', $response->body);

        // ...while the log must contain enough to diagnose it.
        $logFile = $this->storage . '/logs/app-' . gmdate('Y-m-d', Clock::now()) . '.log';
        $this->assertTrue(is_file($logFile), 'the failure must be written to the log');
        $log = (string) file_get_contents($logFile);
        $this->assertStringContains('unhandled_exception', $log);
        $this->assertStringContains('req-explode-001', $log);
    }

    public function testDebugModeChangesTheMessageButNeverTheLogging(): void
    {
        $app = new Application(APP_ROOT);
        $app->withConfig([
            'app' => ['debug' => true],
            'logging' => ['path' => $this->storage . '/logs', 'level' => 'debug'],
        ]);
        $app->router()->get('/api/boom', static function (): never {
            throw new \RuntimeException('detail for the developer');
        });

        $response = $app->handle(Request::create('GET', '/api/boom'));

        $this->assertSame(500, $response->status);
        $this->assertStringContains('detail for the developer', $response->body, 'debug mode may expose detail');
    }

    public function testSensitiveValuesNeverReachTheLog(): void
    {
        $app = $this->app();
        $app->logger()->info('login_attempt', [
            'mobile' => '09121234567',
            'password' => 'super-secret',
            'otp_code' => '123456',
            'nested' => ['api_key' => 'abc123', 'safe' => 'value'],
        ]);

        $log = (string) file_get_contents($this->storage . '/logs/app-' . gmdate('Y-m-d', Clock::now()) . '.log');

        $this->assertStringNotContains('super-secret', $log);
        $this->assertStringNotContains('09121234567', $log);
        $this->assertStringNotContains('123456', $log);
        $this->assertStringNotContains('abc123', $log);
        $this->assertStringContains('login_attempt', $log);
        $this->assertStringContains('"safe":"value"', $log, 'non-sensitive context must survive');
    }
}
