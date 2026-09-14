<?php

declare(strict_types=1);

/**
 * Front controller. This is the only PHP file the web server needs to reach inside
 * the application; everything else lives outside the document root.
 *
 * It works on plain shared hosting: no shell, no build step. Clean URLs need rewriting;
 * where rewriting is unavailable the front controller is still reachable as
 * /index.php?r=/path (the Request object resolves that form), but the links the
 * application generates are clean URLs - the URL section of the install runbook says what
 * a host without rewriting therefore needs (see ADR-0002 and O-20).
 */

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$request = App\Core\Request::fromGlobals();

$app->handle($request)->send();
