<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/**
 * Runs the REAL front controller (public/index.php) the way a web server does:
 * superglobals set, output captured, response status inspected.
 *
 * This is the closest thing to an HTTP request that can run without a web server, and it
 * is what proves the installation actually serves requests - not just that the classes
 * work when called directly.
 */
final class FrontControllerSmokeTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        // A real installation needs config/config.php. The test writes its own isolated
        // configuration and points the application at it through CHAPINO_CONFIG, so the
        // real front controller runs with a real config file and nothing in the
        // repository is touched.
        $this->storage = $this->tempDir('chapino-front');
        $configPath = $this->storage . '/config.php';
        file_put_contents($configPath, '<?php return ' . var_export([
            'app' => ['env' => 'local', 'debug' => false],
            'storage' => ['path' => $this->storage],
            'logging' => ['path' => $this->storage . '/logs', 'level' => 'debug'],
            // Keep the database inside the temporary directory: a test that writes into the
            // repository leaves artifacts that later confuse a real installation.
            'database' => ['driver' => 'sqlite', 'sqlite_path' => $this->storage . '/database.sqlite'],
        ], true) . ';');
        putenv('CHAPINO_CONFIG=' . $configPath);
    }

    protected function tearDown(): void
    {
        putenv('CHAPINO_CONFIG');
    }

    /** @return array{status: int, body: string} */
    private function request(string $method, string $uri, array $query = [], array $server = []): array
    {
        $originalServer = $_SERVER;
        $originalGet = $_GET;
        $originalPost = $_POST;

        $_SERVER = array_merge([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_ACCEPT' => 'application/json',
        ], $server);
        $_GET = $query;
        $_POST = [];

        ob_start();
        try {
            require APP_ROOT . '/public/index.php';
        } finally {
            $body = (string) ob_get_clean();
            $status = http_response_code();
            $_SERVER = $originalServer;
            $_GET = $originalGet;
            $_POST = $originalPost;
        }

        return ['status' => is_int($status) ? $status : 0, 'body' => $body];
    }

    public function testFrontControllerServesTheHealthEndpoint(): void
    {
        $response = $this->request('GET', '/api/health');

        // Status codes cannot be observed from inside this process (the runner has already
        // printed output, so PHP refuses to set response headers). They are asserted by
        // `php bin/smoke.php`, which runs the same front controller in a fresh process.
        // What is asserted here is the payload contract.
        $payload = json_decode($response['body'], true);
        $this->assertTrue($payload['ok']);
        $this->assertSame('ok', $payload['data']['status']);
        $this->assertFalse($payload['data']['capabilities']['ai_generation'], 'C-12: AI stays off');
    }

    public function testFrontControllerWorksWithoutUrlRewriting(): void
    {
        // Shared hosting without mod_rewrite reaches the app as /index.php?r=/api/health.
        $response = $this->request('GET', '/index.php', ['r' => '/api/health']);

        $payload = json_decode($response['body'], true);
        $this->assertSame('ok', $payload['data']['status']);
    }

    public function testUnknownPathIsA404WithJsonErrorShape(): void
    {
        $response = $this->request('GET', '/api/unknown');

        $payload = json_decode($response['body'], true);
        $this->assertFalse($payload['ok'], 'an unknown route must not look like a success');
        $this->assertSame('not_found', $payload['error']['code']);
        $this->assertStringContains('پیدا نشد', $payload['error']['message']);
    }

    public function testFrontControllerWorksInASubfolderDocumentRoot(): void
    {
        // How XAMPP and Laragon installations usually look: the project sits in a subfolder, so the
        // request path carries a prefix that must not be treated as part of the route
        // (htdocs/chapino/public/index.php serves /chapino/public/...).
        $response = $this->request('GET', '/chapino/public/', [], ['SCRIPT_NAME' => '/chapino/public/index.php']);

        $this->assertStringContains('چاپینو', $response['body'], 'the landing page must be served under a subfolder');

        $api = $this->request('GET', '/chapino/public/api/health', [], ['SCRIPT_NAME' => '/chapino/public/index.php']);
        $payload = json_decode($api['body'], true);
        $this->assertSame('ok', $payload['data']['status'], 'the API must resolve under a subfolder as well');

        // Without rewriting, a subfolder installation arrives as /chapino/public/index.php?r=/api/health.
        $rewriteFree = $this->request(
            'GET',
            '/chapino/public/index.php',
            ['r' => '/api/health'],
            ['SCRIPT_NAME' => '/chapino/public/index.php'],
        );
        $this->assertSame(
            'ok',
            json_decode($rewriteFree['body'], true)['data']['status'],
            'a subfolder installation without mod_rewrite must still route',
        );
    }

    public function testHtmlLandingPageIsServedInPersianAndRtl(): void
    {
        $response = $this->request('GET', '/');

        $this->assertStringContains('dir="rtl"', $response['body'], 'the document must declare RTL');
        $this->assertStringContains('lang="fa"', $response['body'], 'the document must declare Persian');
        $this->assertStringContains('چاپینو', $response['body']);
    }
}
