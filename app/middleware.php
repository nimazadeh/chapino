<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * The middleware pipeline, as an explicit ordered list.
 *
 * Middleware is for concerns that apply to many requests. It must not contain business rules, must be
 * safe to run on unauthenticated requests, and must never swallow an exception (backend rule).
 *
 * Each entry is: `function (Request $request, callable $next, Application $app): Response`.
 * The container is passed rather than the configuration because these layers need the session, the
 * rate limiter and the logger, and resolving them once per request here is exactly what the container
 * is for.
 *
 * Order matters and is deliberate:
 *   1. body size  - reject an oversized body before doing any work or touching storage;
 *   2. session    - start only when there is a cookie or the request changes state;
 *   3. csrf       - every state-changing request must prove it came from this site;
 *   4. write rate - unauthenticated writes are limited per client, before they reach a controller.
 */

/**
 * API and form bodies have no legitimate reason to be large. Uploads go through their own endpoint
 * with their own explicit limit, so this guard can be strict.
 *
 * Both the declared and the measured size are checked: `Content-Length` can be omitted or understated,
 * and a limit that a missing header bypasses is decoration rather than a limit.
 */
$maxRequestBodyBytes = 1024 * 512; // 512 KB

return [
    static function (Request $request, callable $next, Application $app) use ($maxRequestBodyBytes): Response {
        $declared = $request->header('content-length');
        $declaredBytes = $declared !== null && ctype_digit($declared) ? (int) $declared : 0;
        $measuredBytes = $request->rawBodyLength() ?? 0;

        if (max($declaredBytes, $measuredBytes) > $maxRequestBodyBytes) {
            throw HttpException::validation(
                ['body' => 'حجم اطلاعات ارسالی بیش از حد مجاز است.'],
                'حجم اطلاعات ارسالی بیش از حد مجاز است.',
            );
        }

        return $next($request);
    },

    /**
     * Session handling.
     *
     * Starting a session on every request would create a session file for every visitor and, worse,
     * would tempt later code into treating "has a session" as "is somebody". It starts when the
     * request already carries a session cookie (so login state is recognised) or when it changes
     * state (so the CSRF token has somewhere to live).
     */
    static function (Request $request, callable $next, Application $app): Response {
        $cookieName = $app->config()->string('security.session_name', 'chapino_session');
        $cookie = $_COOKIE[$cookieName] ?? null;
        $hasCookie = is_string($cookie) && $cookie !== '';

        if ($hasCookie || $request->isStateChanging()) {
            $app->session()->start();
        }

        return $next($request);
    },

    /**
     * CSRF verification.
     *
     * Deny by default: every state-changing method must present the session token, or the request is
     * refused before any controller sees it. A cross-site `Origin` is refused as well; that check
     * applies only when the header is present, because command-line clients do not send one and
     * refusing them would add no protection (they carry no cross-site cookie either).
     */
    static function (Request $request, callable $next, Application $app): Response {
        if (!$request->isStateChanging()) {
            return $next($request);
        }

        $csrf = $app->csrf();
        $originAllowed = $csrf->originAllowed($request);

        if (!$originAllowed || !$csrf->validate($request)) {
            $app->logger()->warning('csrf_rejected', [
                'path' => $request->path,
                'method' => $request->method,
                'origin_allowed' => $originAllowed,
            ]);

            throw new HttpException(
                403,
                'csrf_failed',
                'نشست شما منقضی شده است. صفحه را دوباره باز کنید و دوباره تلاش کنید.',
            );
        }

        return $next($request);
    },

    /**
     * Rate limiting for unauthenticated state-changing requests.
     *
     * An anonymous client may not post unlimited forms: on shared hosting a simple loop can fill the
     * database or burn the monthly request quota. The limit is configuration-driven, and setting it
     * to 0 disables it for an installation that would rather rely on something else.
     */
    static function (Request $request, callable $next, Application $app): Response {
        if (!$request->isStateChanging() || $app->session()->isAuthenticated()) {
            return $next($request);
        }

        $limit = $app->config()->int('security.rate_limits.anonymous_write_per_hour', 30);
        if ($limit <= 0) {
            return $next($request);
        }

        $result = $app->rateLimiter()->hit('anonymous_write', $request->clientIp, $limit, 3600);
        if ($result->allowed) {
            return $next($request);
        }

        // Observable, as the security rule requires: the warning is logged with the numbers, and the
        // response tells the client when to try again instead of pretending the request was invalid.
        $app->logger()->warning('rate_limited', [
            'bucket' => 'anonymous_write',
            'limit' => $result->limit,
            'used' => $result->used,
            'path' => $request->path,
        ]);

        return Response::error(
            'rate_limited',
            'تعداد درخواست‌های شما بیش از حد مجاز است. چند دقیقه دیگر دوباره تلاش کنید.',
            429,
            [],
            $request->requestId,
        )->withHeader('Retry-After', (string) $result->retryAfterSeconds);
    },
];
