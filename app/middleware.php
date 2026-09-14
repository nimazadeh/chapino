<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * The middleware pipeline, as an explicit ordered list.
 *
 * Middleware is for concerns that apply to many requests. It must not contain business
 * rules, must be safe to run on unauthenticated requests, and must never swallow an
 * exception (see the backend rule).
 *
 * Each entry is: `function (Request $request, callable $next, Config $config): Response`.
 *
 * Phase 0 currently holds one layer. Session handling, CSRF verification and rate
 * limiting are added in the next Phase 0 slice, together with the storage they depend
 * on - they are not stubbed here, because a stub that looks like a control is worse
 * than no control at all.
 */

/**
 * API and form bodies have no legitimate reason to be large. Uploads go through their
 * own endpoint with their own explicit limit, so this guard can be strict.
 */
$maxRequestBodyBytes = 1024 * 512; // 512 KB

return [
    static function (Request $request, callable $next, Config $config) use ($maxRequestBodyBytes): Response {
        $length = $request->header('content-length');
        if ($length !== null && ctype_digit($length) && (int) $length > $maxRequestBodyBytes) {
            throw HttpException::validation(
                ['body' => 'حجم اطلاعات ارسالی بیش از حد مجاز است.'],
                'حجم اطلاعات ارسالی بیش از حد مجاز است.',
            );
        }

        return $next($request);
    },
];
