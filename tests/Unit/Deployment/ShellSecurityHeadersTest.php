<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Unit\Deployment;

use Mailadmin\Http\SecurityHeaders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Closes O-13 and keeps it closed.
 *
 * The security headers from architecture 5.7 used to reach only `/api/*`,
 * because `SecurityHeadersMiddleware` is the only thing that set them and the
 * front controller only ever answers there. The SPA shell — the single document
 * in which an XSS hole would actually be exploitable, and the reason
 * `script-src 'self'` exists at all (E-15) — was served straight off disk with
 * no policy whatsoever. That made the trade-off in U-35 pointless: it weakened
 * `style-src` in a policy the browser never received.
 *
 * W1 fixed it in `public/.htaccess` and in both vHost examples. This test keeps
 * the four copies of the list saying the same thing. Two copies that drift are
 * worse than one copy in the wrong place, and nothing else would notice: a
 * missing header produces no error, just a page that is quietly less protected.
 */
final class ShellSecurityHeadersTest extends TestCase
{
    /**
     * The files that must carry the policy, and how a header is written in each.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function headerFileProvider(): iterable
    {
        yield 'public/.htaccess' => ['public/.htaccess', 'apache'];
        yield 'apache vHost example' => ['docs/deployment/apache-vhost.conf.example', 'apache'];
        yield 'nginx example' => ['docs/deployment/nginx.conf.example', 'nginx'];
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function contents(string $relativePath): string
    {
        $file = self::projectRoot() . '/' . $relativePath;

        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    #[DataProvider('headerFileProvider')]
    public function testTheShellCarriesEverySecurityHeader(string $relativePath, string $flavour): void
    {
        $contents = self::contents($relativePath);

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            self::assertStringContainsString($name, $contents, sprintf(
                '%s does not set %s. The statically served shell would then be less protected '
                . 'than every /api/ response (architecture 5.7, O-13).',
                $relativePath,
                $name,
            ));

            self::assertStringContainsString($value, $contents, sprintf(
                '%s sets %s with a value that differs from src/Http/SecurityHeaders.php. '
                . 'Two policies that drift are worse than one.',
                $relativePath,
                $name,
            ));
        }

        // Without `always` neither server sends the headers on an error
        // response — and a 404 is a document the browser renders too.
        $marker = $flavour === 'apache' ? 'Header always set' : 'always;';

        self::assertStringContainsString($marker, $contents, sprintf(
            '%s sets the headers only on successful responses.',
            $relativePath,
        ));
    }

    /**
     * The half of the policy that carries the load, spelled out where a careless
     * edit would otherwise pass every other assertion in this file.
     */
    public function testScriptSrcStaysStrictEverywhere(): void
    {
        $sources = [
            'src/Http/SecurityHeaders.php',
            'public/.htaccess',
            'docs/deployment/apache-vhost.conf.example',
            'docs/deployment/nginx.conf.example',
        ];

        foreach ($sources as $relativePath) {
            $contents = self::contents($relativePath);

            self::assertStringContainsString("script-src 'self'", $contents, sprintf(
                '%s no longer states script-src \'self\'.',
                $relativePath,
            ));

            // The quoted form is the one a policy uses; the unquoted word turns
            // up in the prose that explains why it is absent.
            self::assertStringNotContainsString("'unsafe-eval'", $contents, sprintf(
                '%s introduces unsafe-eval. It appears in no policy, anywhere (U-35).',
                $relativePath,
            ));

            self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $contents, sprintf(
                '%s weakens script-src. Only style-src carries \'unsafe-inline\', and that is '
                . 'the end of it — any further loosening is an escalation case (U-35).',
                $relativePath,
            ));
        }
    }

    /**
     * The shell must not be cached, or a deployment would not take effect until
     * every browser happened to revalidate (architecture 11.3).
     */
    public function testTheShellIsNotCached(): void
    {
        self::assertMatchesRegularExpression(
            '/index\\\\?\.html.*\R.*Cache-Control "no-store"/',
            self::contents('public/.htaccess'),
        );
    }
}
