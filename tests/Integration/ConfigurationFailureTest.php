<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use Tests\TestCase;

/**
 * An unconfigured installation is the first thing a real deployment does. It must produce an
 * honest, actionable answer - never a blank page, a stack trace, or a silent success.
 */
final class ConfigurationFailureTest extends TestCase
{
    public function testMissingConfigurationProducesA503WithInstallInstructions(): void
    {
        $missing = $this->tempDir('chapino-missing') . '/config.php';
        putenv('CHAPINO_CONFIG=' . $missing);

        try {
            $app = new Application(APP_ROOT);
            $response = $app->handle(Request::create('GET', '/api/health'));
        } finally {
            putenv('CHAPINO_CONFIG');
        }

        $this->assertSame(503, $response->status, 'an unconfigured installation is unavailable, not broken');
        $payload = json_decode($response->body, true);
        $this->assertSame('config_error', $payload['error']['code']);
        $this->assertStringContains('config/config.example.php', $payload['error']['message']);
        $this->assertStringNotContains('Stack trace', $response->body);
        $this->assertStringNotContains(APP_ROOT, $response->body, 'the absolute server path must never leak');
        $this->assertStringNotContains('/home/', $response->body, 'no filesystem path may be exposed');
    }

    public function testInvalidConfigurationFileProducesA503AsWell(): void
    {
        $dir = $this->tempDir('chapino-invalid');
        $path = $dir . '/config.php';
        file_put_contents($path, "<?php return 'not-an-array';");
        putenv('CHAPINO_CONFIG=' . $path);

        try {
            $app = new Application(APP_ROOT);
            $response = $app->handle(Request::create('GET', '/'));
        } finally {
            putenv('CHAPINO_CONFIG');
        }

        $this->assertSame(503, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame('config_error', $payload['error']['code']);
    }
}
