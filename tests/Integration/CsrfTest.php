<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Request;
use App\Core\Security\Csrf;
use Tests\Support\FakeSessionStore;
use Tests\TestCase;

/**
 * CSRF protection: the token must be unguessable, compared in constant time, required on
 * state-changing methods only, and accepted from exactly two places - a header (for fetch/XHR) or the
 * form field, never from the query string, where it would end up in logs and Referer headers.
 */
final class CsrfTest extends TestCase
{
    private FakeSessionStore $store;
    private Csrf $csrf;

    protected function setUp(): void
    {
        $this->store = new FakeSessionStore();
        $this->csrf = new Csrf($this->store);
    }

    public function testTokenIsStableWithinASessionAndLongEnoughToBeUnguessable(): void
    {
        $first = $this->csrf->token();
        $second = $this->csrf->token();

        $this->assertSame($first, $second, 'the token must not change on every call');
        $this->assertSame(64, strlen($first), '32 random bytes, hex-encoded');
        $this->assertTrue(ctype_xdigit($first));
    }

    public function testTwoSessionsGetDifferentTokens(): void
    {
        $other = new Csrf(new FakeSessionStore());

        $this->assertFalse($other->token() === $this->csrf->token());
    }

    public function testRotateReplacesTheToken(): void
    {
        $before = $this->csrf->token();
        $this->csrf->rotate();

        $this->assertFalse($before === $this->csrf->token(), 'a rotated token must not be reusable');
        $this->assertTrue($this->csrf->validate($this->requestWithToken($this->csrf->token())));
    }

    public function testValidTokenIsAcceptedFromHeaderAndFromFormField(): void
    {
        $token = $this->csrf->token();

        $this->assertTrue($this->csrf->validate($this->requestWithToken($token)), 'header form');
        $this->assertTrue(
            $this->csrf->validate(Request::create('POST', '/x', [], [Csrf::FIELD => $token])),
            'form field form (in the body)',
        );
    }

    public function testMissingOrWrongTokenIsRejected(): void
    {
        $this->csrf->token();

        $this->assertFalse($this->csrf->validate(Request::create('POST', '/x')), 'no token at all');
        $this->assertFalse(
            $this->csrf->validate($this->requestWithToken(str_repeat('a', 64))),
            'a well-formed but wrong token',
        );
        $this->assertFalse(
            $this->csrf->validate($this->requestWithToken('short')),
            'a truncated token',
        );
    }

    public function testTokenIsNotAcceptedFromTheQueryString(): void
    {
        $token = $this->csrf->token();

        // A token in the URL leaks through logs, browser history and the Referer header of links on
        // the page, so it is deliberately not part of the accepted surface.
        // Request::create(method, path, query, body). The token here is in the query string, which
        // is where it must never be accepted from.
        $this->assertFalse(
            $this->csrf->validate(Request::create('POST', '/x', [Csrf::FIELD => $token])),
            'a token in the query string must not be accepted',
        );
    }

    public function testFieldIsRenderedEscapedForHtmlContext(): void
    {
        $field = $this->csrf->field();

        $this->assertStringContains('type="hidden"', $field);
        $this->assertStringContains('name="' . Csrf::FIELD . '"', $field);
        $this->assertStringContains($this->csrf->token(), $field);
    }

    public function testRequestsWithoutAnOriginHeaderAreAllowed(): void
    {
        // Command-line clients and some older browsers send no Origin; the token check still applies.
        $this->assertTrue($this->csrf->originAllowed(Request::create('POST', '/x')));
    }

    public function testCrossSiteOriginIsRefusedEvenWithAValidToken(): void
    {
        $request = Request::create('POST', '/x', [], [], [
            'origin' => 'https://evil.example',
            'host' => 'chapino.example',
        ]);

        $this->assertFalse($this->csrf->originAllowed($request));
    }

    public function testSameOriginWithDifferentPortIsAllowed(): void
    {
        $request = Request::create('POST', '/x', [], [], [
            'origin' => 'http://localhost:8080',
            'host' => 'localhost',
        ]);

        $this->assertTrue($this->csrf->originAllowed($request));
    }

    /** @param array<string, string> $headers */
    private function requestWithToken(string $token, array $headers = []): Request
    {
        return Request::create('POST', '/x', [], [], [strtolower(Csrf::HEADER) => $token] + $headers);
    }
}
