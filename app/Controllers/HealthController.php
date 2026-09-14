<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;

/**
 * Health and capability endpoint.
 *
 * Purpose: a machine-readable answer to "is this installation alive, and what is it?".
 * It must never expose secrets, file paths, versions of dependencies or the database
 * credentials - only booleans and non-sensitive facts (see the security rule).
 */
final class HealthController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        $config = $this->app->config();

        return Response::ok([
            'status' => 'ok',
            'app' => $config->string('app.name'),
            'environment' => $config->string('app.env'),
            'time' => \App\Core\Clock::nowIso(),
            'request_id' => $request->requestId,
            'capabilities' => [
                // Owner decision C-12: the AI entry point exists but is switched off.
                // The client renders it disabled; the server refuses it regardless
                // (ADR-0003).
                'ai_generation' => $config->bool('ai.enabled', false),
            ],
            'integrations' => [
                'payment' => ['configured' => $config->string('payment.merchant_id') !== ''],
                'sms' => ['configured' => $config->string('sms.api_key') !== ''],
            ],
            // The database is reported as a fact, with no credentials and no path: enough for the
            // owner to see whether the host wiring is right, useless to anyone probing the site.
            'database' => $this->databaseStatus(),
        ]);
    }

    /** @return array{driver: string, connected: bool, migrations_pending: int|null} */
    private function databaseStatus(): array
    {
        $config = $this->app->config();
        $driver = $config->string('database.driver', 'sqlite');

        try {
            $connection = $this->app->database();
            $schema = new \App\Core\Database\Schema(
                $connection,
                $config->string('database.charset', 'utf8mb4'),
                $config->string('database.collation', 'utf8mb4_unicode_ci'),
            );
            $status = (new \App\Core\Database\Migrator(
                $connection,
                $schema,
                $this->app->root() . '/database/migrations',
            ))->status();

            return ['driver' => $driver, 'connected' => true, 'migrations_pending' => count($status['pending'])];
        } catch (\Throwable) {
            // A health endpoint must answer even when the database does not; the detail stays in
            // the log, and no message from the driver is echoed here.
            return ['driver' => $driver, 'connected' => false, 'migrations_pending' => null];
        }
    }
}
