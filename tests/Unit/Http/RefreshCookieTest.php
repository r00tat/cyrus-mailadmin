<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http;

use Mailadmin\Http\RefreshCookie;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The four attributes of the one cookie this application sets (E-15).
 *
 * Each of them is asserted separately and by name, because each is load-bearing
 * and a missing one is invisible in every functional test: the session would
 * work perfectly with `HttpOnly` dropped, right up until the day a script reads
 * the refresh token and turns a one-hour compromise into a twelve-hour one.
 */
#[CoversClass(RefreshCookie::class)]
final class RefreshCookieTest extends TestCase
{
    public function testTheRefreshTokenIsOutOfReachOfScript(): void
    {
        self::assertStringContainsString('HttpOnly', $this->set());
    }

    public function testTheCookieNeverTravelsOverPlainHttp(): void
    {
        self::assertStringContainsString('Secure', $this->set());
    }

    /**
     * `POST /auth/refresh` is the single endpoint a browser reaches with a
     * cookie rather than a bearer header, so it is the single endpoint with a
     * CSRF surface (architecture 7.3).
     */
    public function testCrossSiteRequestsDoNotCarryIt(): void
    {
        self::assertStringContainsString('SameSite=Strict', $this->set());
    }

    /**
     * Narrow enough that no resource endpoint and none of the SPA's own assets
     * ever see it.
     */
    public function testItIsSentToTheAuthEndpointsAndNowhereElse(): void
    {
        self::assertStringContainsString('Path=/api/v1/auth;', $this->set() . ';');
        self::assertSame('/api/v1/auth', (new RefreshCookie())->path());
    }

    /**
     * Deployment variant B mounts the whole project under a prefix (U-25). A
     * cookie whose path ignored that would be sent with every request or with
     * none.
     */
    public function testTheMountPrefixIsPartOfThePath(): void
    {
        self::assertSame('/mailadmin/api/v1/auth', (new RefreshCookie('/mailadmin'))->path());
        self::assertSame('/mailadmin/api/v1/auth', (new RefreshCookie('/mailadmin/'))->path());
    }

    /**
     * From the family deadline, so the browser stops sending a token at the
     * moment it stops working rather than keeping a session cookie that outlives
     * it.
     */
    public function testTheLifetimeComesFromTheFamilyDeadline(): void
    {
        self::assertStringContainsString('Max-Age=43200', $this->set(43200));
    }

    public function testANegativeLifetimeBecomesZeroRatherThanAnEternalCookie(): void
    {
        self::assertStringContainsString('Max-Age=0', $this->set(-5));
    }

    /**
     * Both `Max-Age=0` and a past `Expires`: sending only one is the usual
     * reason a cleared cookie comes back.
     */
    public function testClearingSaysSoInBothDialects(): void
    {
        $header = (new RefreshCookie())
            ->clearOn((new Psr17Factory())->createResponse(204))
            ->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('Max-Age=0', $header);
        self::assertStringContainsString('Expires=Thu, 01 Jan 1970', $header);
    }

    public function testItReadsBackWhatTheBrowserSends(): void
    {
        $request = (new Psr17Factory())
            ->createServerRequest('POST', 'https://mail.example.test/api/v1/auth/refresh')
            ->withCookieParams([RefreshCookie::NAME => 'the-token']);

        self::assertSame('the-token', (new RefreshCookie())->readFrom($request));
    }

    public function testAnAbsentOrEmptyCookieIsNoCookie(): void
    {
        $cookie = new RefreshCookie();
        $request = (new Psr17Factory())
            ->createServerRequest('POST', 'https://mail.example.test/api/v1/auth/refresh');

        self::assertNull($cookie->readFrom($request));
        self::assertNull($cookie->readFrom($request->withCookieParams([RefreshCookie::NAME => '  '])));
    }

    private function set(int $maxAge = 43200): string
    {
        return (new RefreshCookie())
            ->attachTo((new Psr17Factory())->createResponse(200), 'the-token', $maxAge)
            ->getHeaderLine('Set-Cookie');
    }
}
