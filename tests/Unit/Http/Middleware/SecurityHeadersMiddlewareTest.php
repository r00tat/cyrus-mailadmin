<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Http\Middleware;

use Mailadmin\Http\Middleware\SecurityHeadersMiddleware;
use Mailadmin\Http\SecurityHeaders;
use Mailadmin\Tests\Support\CallableHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityHeadersMiddleware::class)]
#[CoversClass(SecurityHeaders::class)]
final class SecurityHeadersMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * The values are taken verbatim from architecture 5.7. They are asserted as
     * literals on purpose: comparing against the constant would pass even if the
     * constant were changed, and loosening these headers is an escalation case,
     * not a build fix.
     */
    #[DataProvider('expectedHeaders')]
    public function testTheHeaderIsSetWithTheValueFromTheArchitecture(string $header, string $value): void
    {
        $response = $this->execute();

        self::assertSame($value, $response->getHeaderLine($header));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expectedHeaders(): iterable
    {
        yield 'Content-Security-Policy' => [
            'Content-Security-Policy',
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; "
            . "base-uri 'none'; object-src 'none'",
        ];
        yield 'Strict-Transport-Security' => [
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains',
        ];
        yield 'X-Content-Type-Options' => ['X-Content-Type-Options', 'nosniff'];
        yield 'Referrer-Policy' => ['Referrer-Policy', 'no-referrer'];
    }

    public function testThePermissionsPolicyDeniesCameraMicrophoneAndGeolocation(): void
    {
        $policy = $this->execute()->getHeaderLine('Permissions-Policy');

        self::assertStringContainsString('camera=()', $policy);
        self::assertStringContainsString('microphone=()', $policy);
        self::assertStringContainsString('geolocation=()', $policy);
    }

    /**
     * E-15 accepts the access token in localStorage and names the CSP as what
     * carries that risk — specifically `script-src`, because stealing the token
     * takes script execution. If this test ever fails, the fix is in the
     * bundler, not here.
     *
     * `style-src 'unsafe-inline'` is deliberate (U-35, Material UI) and is
     * asserted separately below, so that loosening it further cannot slip
     * through as "the test already allowed unsafe-inline".
     */
    public function testNoDirectiveThatCanExecuteCodeAllowsInliningOrEval(): void
    {
        $directives = $this->contentSecurityPolicyDirectives();

        foreach (['default-src', 'script-src'] as $directive) {
            self::assertArrayHasKey($directive, $directives);
            self::assertNotContains("'unsafe-inline'", $directives[$directive]);
        }

        $policy = $this->execute()->getHeaderLine('Content-Security-Policy');
        self::assertStringNotContainsString('unsafe-eval', $policy);
        self::assertStringNotContainsString('*', $policy);
    }

    /**
     * U-35: Material UI styles at runtime through Emotion, so inline styles are
     * allowed — but only there, and no external origin is reachable, which is
     * what keeps CSS-based exfiltration from paying off.
     */
    public function testInliningIsAllowedForStylesAndNowhereElse(): void
    {
        $directives = $this->contentSecurityPolicyDirectives();

        self::assertSame(["'self'", "'unsafe-inline'"], $directives['style-src'] ?? []);

        foreach ($directives as $directive => $sources) {
            if ($directive === 'style-src') {
                continue;
            }

            self::assertNotContains("'unsafe-inline'", $sources, $directive);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function contentSecurityPolicyDirectives(): array
    {
        $directives = [];

        foreach (explode(';', $this->execute()->getHeaderLine('Content-Security-Policy')) as $part) {
            $sources = preg_split('/\s+/', trim($part), flags: PREG_SPLIT_NO_EMPTY) ?: [];

            if ($sources === []) {
                continue;
            }

            $directives[array_shift($sources)] = array_values($sources);
        }

        return $directives;
    }

    public function testManagementDataIsNotCached(): void
    {
        self::assertSame('no-store', $this->execute()->getHeaderLine('Cache-Control'));
    }

    public function testAnExplicitCacheControlFromTheHandlerSurvives(): void
    {
        // The built frontend bundle (W1) may be cached; the security headers still
        // apply to it.
        $response = $this->execute(
            $this->factory->createResponse()->withHeader('Cache-Control', 'public, max-age=31536000, immutable'),
        );

        self::assertSame('public, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAHandlerCannotWeakenThePolicy(): void
    {
        $response = $this->execute(
            $this->factory->createResponse()->withHeader('Content-Security-Policy', "default-src *; script-src 'unsafe-inline'"),
        );

        self::assertSame(SecurityHeaders::CONTENT_SECURITY_POLICY, $response->getHeaderLine('Content-Security-Policy'));
    }

    private function execute(?\Psr\Http\Message\ResponseInterface $inner = null): \Psr\Http\Message\ResponseInterface
    {
        $middleware = new SecurityHeadersMiddleware();

        return $middleware->process(
            $this->factory->createServerRequest('GET', 'https://mail.example.test/api/v1/domains'),
            CallableHandler::returning($inner ?? $this->factory->createResponse()),
        );
    }
}
