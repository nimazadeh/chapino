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
        ]);
    }
}
