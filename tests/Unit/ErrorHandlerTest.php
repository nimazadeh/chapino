<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ConfigurationException;
use App\Core\Database\DatabaseException;
use App\Core\ErrorHandler;
use App\Core\HttpException;
use App\Core\Logger;
use Tests\TestCase;

/**
 * What the client sees when something fails.
 *
 * These tests exist because error handling is where internal detail leaks: a stack trace, a file
 * path or a raw SQL fragment in an HTTP response is a security defect, not a debugging convenience
 * (see the security rule). The other half is honesty: an unconfigured installation must say so with
 * 503 and a real explanation instead of a blank page or a 200.
 */
final class ErrorHandlerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $logs = [];

    private function handler(bool $debug = false): ErrorHandler
    {
        // The logger writes to a file; the test only needs the rendered response, and the file
        // lives in a temporary directory.
        $this->logs = [];

        return new ErrorHandler(new Logger($this->tempDir('chapino-logs'), 'debug'), $debug);
    }

    public function testConfigurationFailureBecomesA503WithAZeroConfigHint(): void
    {
        $response = $this->handler()->errorResponse(
            new ConfigurationException('فایل پیکربندی پیدا نشد. راهنما: config/config.example.php را کپی کنید.', 'config_missing'),
            'req-1',
        );

        $this->assertSame(503, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame('config_error', $payload['error']['code']);
        $this->assertSame('req-1', $payload['error']['request_id'], 'the request id must be traceable');
        $this->assertStringContains('config.example.php', $payload['error']['message']);
    }

    public function testHttpExceptionKeepsItsStatusAndFieldErrors(): void
    {
        $response = $this->handler()->errorResponse(
            HttpException::validation(['mobile' => 'شماره موبایل معتبر نیست.']),
        );

        $this->assertSame(422, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame('validation_failed', $payload['error']['code']);
        $this->assertSame('شماره موبایل معتبر نیست.', $payload['error']['fields']['mobile']);
    }

    public function testDatabaseSetupProblemBecomesAnActionable503(): void
    {
        $response = $this->handler()->errorResponse(new DatabaseException(
            'افزونه pdo_mysql روی این سرور فعال نیست. برای ادامه، آن را در تنظیمات PHP (php.ini یا پنل هاست) فعال کنید.',
            'database_driver_missing',
        ));

        $this->assertSame(503, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame('setup_required', $payload['error']['code']);
        $this->assertStringContains('pdo_mysql', $payload['error']['message'], 'the owner must learn what to enable');
    }

    public function testMissingSchemaIsReportedAsASetupProblemWithTheExactCommand(): void
    {
        // The most likely state of a fresh upload to shared hosting: files uploaded, migrations not
        // run. The owner must be told the command that fixes it, not shown "unexpected error".
        $response = $this->handler()->errorResponse(new DatabaseException(
            'ساختار پایگاه‌داده کامل نیست (جدول موردنیاز ساخته نشده است). '
            . 'برای تکمیل نصب، دستور php bin/migrate.php را اجرا کنید.',
            'database_schema_missing',
            'HY000',
        ));

        $this->assertSame(503, $response->status);
        $payload = json_decode($response->body, true);
        $this->assertSame('setup_required', $payload['error']['code']);
        $this->assertStringContains('migrate.php', $payload['error']['message']);
    }

    public function testRuntimeDatabaseFailureStaysAGeneric500WithoutInternals(): void
    {
        $response = $this->handler()->errorResponse(new DatabaseException(
            'SQLSTATE[42S02]: Base table or view not found: 1146 Table \'shop.orders\' doesn\'t exist',
            'database_error',
            '42S02',
        ));

        $this->assertSame(500, $response->status, 'an unexpected database failure must not claim to be a setup problem');
        $payload = json_decode($response->body, true);
        $this->assertSame('server_error', $payload['error']['code']);

        $body = (string) $response->body;
        $this->assertStringNotContains('SQLSTATE', $body, 'driver detail must never reach a client');
        $this->assertStringNotContains('orders', $body, 'table names must not leak');
        $this->assertStringNotContains('/home/', $body, 'server paths must not leak');
        $this->assertStringNotContains('PDO', $body);
    }

    public function testDebugModeExplainsMoreButStillNeverLeaksAPath(): void
    {
        $response = $this->handler(true)->errorResponse(new \RuntimeException('inner detail'));

        $this->assertSame(500, $response->status);
        $this->assertStringContains('inner detail', (string) $response->body, 'debug mode is for the developer');
        $this->assertStringNotContains('/home/', (string) $response->body);
    }

    public function testUnknownErrorIsAGenericPersianMessage(): void
    {
        $payload = json_decode($this->handler()->errorResponse(new \RuntimeException('boom'))->body, true);

        $this->assertSame('server_error', $payload['error']['code']);
        $this->assertStringContains('دوباره تلاش کنید', $payload['error']['message']);
        $this->assertStringNotContains('boom', $payload['error']['message']);
    }
}
