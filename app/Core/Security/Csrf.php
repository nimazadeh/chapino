<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Request;

/**
 * CSRF protection for state-changing requests.
 *
 * The strategy is stated, because the security rule requires the strategy to be explicit rather than
 * implied:
 *   1. the session cookie is `SameSite=Lax`, so a cross-site POST does not carry the session at all;
 *   2. on top of that, every state-changing request must present a token that only a page served by
 *      this application could know (synchroniser token pattern, stored in the session);
 *   3. when the browser sends an `Origin` header, its host must match the request host - a cheap
 *      and independent third check that catches a misconfigured cookie policy.
 *
 * The token lives in the session, is compared in constant time, and is rotated whenever the
 * privilege level changes (login and logout). Tokens are never logged and never placed in a URL.
 */
final class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private readonly SessionStore $session)
    {
    }

    /** The current token, created on first use. */
    public function token(): string
    {
        $existing = $this->session->get(self::SESSION_KEY);
        if (is_string($existing) && strlen($existing) === 64) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->put(self::SESSION_KEY, $token);

        return $token;
    }

    /** Ready-to-print hidden input, so a form cannot forget the token formatting. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8'),
        );
    }

    /** Rotates the token: called on login and logout, where the privilege level changes. */
    public function rotate(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->token();
    }

    /** True when the request carries the session's token. */
    public function validate(Request $request): bool
    {
        $presented = $request->header(self::HEADER);
        if ($presented === null || $presented === '') {
            // Body only, never the query string: the token must not be able to travel in a URL.
            $candidate = $request->body(self::FIELD);
            $presented = is_string($candidate) ? $candidate : '';
        }

        $expected = $this->session->get(self::SESSION_KEY);
        if (!is_string($expected) || $expected === '' || $presented === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }

    /**
     * True when the request may be considered same-origin.
     *
     * Only checked when the browser sends Origin: command-line tools and some older clients do not,
     * and refusing those would break legitimate API use without adding protection (they do not send a
     * session cookie from another site either).
     */
    public function originAllowed(Request $request): bool
    {
        $origin = $request->header('origin');
        if ($origin === null || $origin === '') {
            return true;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = $request->header('host');
        if (!is_string($originHost) || $originHost === '') {
            return false;
        }
        if (!is_string($requestHost) || $requestHost === '') {
            return true; // no Host header: nothing to compare against, the token check still applies
        }

        // Compare host names only; a port difference is not a cross-site request.
        $requestHost = explode(':', $requestHost)[0];

        return strcasecmp($originHost, $requestHost) === 0;
    }
}
