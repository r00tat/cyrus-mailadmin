<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http;

use Mailadmin\Http\ServerRequestFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerRequestFactory::class)]
final class ServerRequestFactoryTest extends TestCase
{
    private ServerRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ServerRequestFactory(new Psr17Factory());
    }

    public function testMethodPathAndQueryAreTakenFromTheServerArray(): void
    {
        $request = $this->factory->create([
            'REQUEST_METHOD' => 'PATCH',
            'REQUEST_URI' => '/api/v1/domains/example.test?verbose=1',
            'HTTP_HOST' => 'mail.example.test',
            'HTTPS' => 'on',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ], ['verbose' => '1']);

        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('mail.example.test', $request->getUri()->getHost());
        self::assertSame('/api/v1/domains/example.test', $request->getUri()->getPath());
        self::assertSame('verbose=1', $request->getUri()->getQuery());
        self::assertSame(['verbose' => '1'], $request->getQueryParams());
        self::assertSame('1.1', $request->getProtocolVersion());
    }

    public function testHttpsOffMeansHttp(): void
    {
        $request = $this->factory->create([
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'mail.example.test',
            'HTTPS' => 'off',
        ]);

        self::assertSame('http', $request->getUri()->getScheme());
    }

    /**
     * `X-Forwarded-*` is deliberately not honoured: trusting it without a
     * configured list of trusted proxies would let a client dictate the scheme
     * and host the application believes it runs under.
     */
    public function testForwardedHeadersDoNotChangeTheSchemeOrHost(): void
    {
        $request = $this->factory->create([
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'mail.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
        ]);

        self::assertSame('http', $request->getUri()->getScheme());
        self::assertSame('mail.example.test', $request->getUri()->getHost());

        // The headers are still visible to anything that explicitly wants them.
        self::assertSame('https', $request->getHeaderLine('X-Forwarded-Proto'));
    }

    public function testHeadersAreConvertedFromTheServerArray(): void
    {
        $request = $this->factory->create([
            'REQUEST_URI' => '/api/v1/domains',
            'HTTP_HOST' => 'mail.example.test',
            'HTTP_AUTHORIZATION' => 'Bearer token-value',
            'HTTP_X_REQUEST_ID' => 'abc123def456',
            'HTTP_ACCEPT_LANGUAGE' => 'de-AT,de;q=0.9',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '17',
            'DOCUMENT_ROOT' => '/srv/mailadmin/public',
        ]);

        self::assertSame('Bearer token-value', $request->getHeaderLine('Authorization'));
        self::assertSame('abc123def456', $request->getHeaderLine('X-Request-Id'));
        self::assertSame('de-AT,de;q=0.9', $request->getHeaderLine('Accept-Language'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('17', $request->getHeaderLine('Content-Length'));
        self::assertFalse($request->hasHeader('Document-Root'));
    }

    public function testCookiesAreCarriedOver(): void
    {
        $request = $this->factory->create(
            ['REQUEST_URI' => '/api/v1/auth/refresh', 'HTTP_HOST' => 'mail.example.test'],
            [],
            ['mailadmin_refresh' => 'opaque-value'],
        );

        self::assertSame(['mailadmin_refresh' => 'opaque-value'], $request->getCookieParams());
    }

    public function testAMissingHostFallsBackToTheServerNameAndPort(): void
    {
        $request = $this->factory->create([
            'REQUEST_URI' => '/',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '8080',
        ]);

        self::assertSame('localhost', $request->getUri()->getHost());
        self::assertSame(8080, $request->getUri()->getPort());
    }

    public function testAnEmptyServerArrayStillProducesAUsableRequest(): void
    {
        $request = $this->factory->create([]);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/', $request->getUri()->getPath());
        self::assertSame('localhost', $request->getUri()->getHost());
    }

    public function testARequestUriWithoutALeadingSlashIsNormalised(): void
    {
        $request = $this->factory->create([
            'REQUEST_URI' => 'api/v1/domains',
            'HTTP_HOST' => 'mail.example.test',
        ]);

        self::assertSame('/api/v1/domains', $request->getUri()->getPath());
    }

    public function testNonScalarServerEntriesAreSkipped(): void
    {
        $headers = ServerRequestFactory::headers([
            'HTTP_X_GOOD' => 'yes',
            'HTTP_X_BAD' => ['array'],
        ]);

        self::assertSame(['X-Good' => 'yes'], $headers);
    }

    /**
     * Apache behind PHP-FPM drops the header; public/.htaccess passes it on as
     * an environment variable that arrives with a REDIRECT_ prefix.
     */
    public function testTheAuthorizationHeaderIsRecoveredFromTheRedirectVariable(): void
    {
        $headers = ServerRequestFactory::headers([
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi',
        ]);

        self::assertSame(['Authorization' => 'Bearer abc.def.ghi'], $headers);
    }

    public function testADirectAuthorizationHeaderWinsOverTheRedirectVariable(): void
    {
        $headers = ServerRequestFactory::headers([
            'HTTP_AUTHORIZATION' => 'Bearer direct',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected',
        ]);

        self::assertSame(['Authorization' => 'Bearer direct'], $headers);
    }

    public function testOtherRedirectVariablesDoNotBecomeHeaders(): void
    {
        $headers = ServerRequestFactory::headers([
            'REDIRECT_HTTP_X_ROLE' => 'superuser',
            'REDIRECT_HTTP_AUTHORIZATION' => '',
        ]);

        self::assertSame([], $headers);
    }
}
