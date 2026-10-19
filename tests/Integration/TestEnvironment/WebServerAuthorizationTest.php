<?php

declare(strict_types=1);

namespace Mailadmin\Tests\Integration\TestEnvironment;

use PHPUnit\Framework\TestCase;

/**
 * A bearer token survives the trip through Apache and PHP-FPM.
 *
 * mod_proxy_fcgi drops the Authorization header unless public/.htaccess hands
 * it on. The contract tests call the kernel directly and cannot see that, so
 * the API answered every authenticated request with 401 in a real browser
 * while the whole suite was green. This test goes through the web server.
 *
 * Signing in rehashes the fixture administrator's DES-crypt hash to Argon2id
 * (S1); the password stays the same, so the test can be repeated.
 */
final class WebServerAuthorizationTest extends TestCase
{
    use RequiresTestEnvironment;

    /** Synthetic fixture credentials from docker/db/fixtures/20-synthetic-data.sql. */
    private const string USER = 'superadmin';
    private const string PASSWORD = 'superadmin-secret-2026';

    protected function setUp(): void
    {
        $this->requireFixtures();
    }

    public function testATokenIssuedThroughTheWebServerIsAcceptedByIt(): void
    {
        [$status, $body] = self::request('POST', '/api/v1/auth/token', null, json_encode([
            'username' => self::USER,
            'password' => self::PASSWORD,
        ], JSON_THROW_ON_ERROR));

        self::assertSame(200, $status, $body);

        $token = json_decode($body, true, 512, JSON_THROW_ON_ERROR)['access_token'] ?? null;
        self::assertIsString($token);

        [$status, $body] = self::request('GET', '/api/v1/auth/me', $token);

        self::assertSame(200, $status, $body);
        self::assertSame(self::USER, json_decode($body, true, 512, JSON_THROW_ON_ERROR)['username'] ?? null);
    }

    public function testAMissingTokenIsStillRefused(): void
    {
        [$status] = self::request('GET', '/api/v1/auth/me', null);

        self::assertSame(401, $status);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function request(string $method, string $path, ?string $token, ?string $json = null): array
    {
        $headers = ['Accept: application/json'];

        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $json ?? '',
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        $body = @file_get_contents(TestEnvironment::webBaseUrl() . $path, false, $context);

        if ($body === false) {
            self::markTestSkipped('The web server does not answer at ' . TestEnvironment::webBaseUrl() . '.');
        }

        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m) === 1 ? (int) $m[1] : 0;

        return [$status, $body];
    }
}
