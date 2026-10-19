<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Builds a PSR-7 server request from the SAPI superglobals.
 *
 * `nyholm/psr7` deliberately ships without this — it lives in the separate
 * package `nyholm/psr7-server`. Since the dependency list in architecture 3.1 is
 * closed (E-12) and this is roughly sixty lines, it is written here.
 *
 * Two decisions worth knowing:
 *
 *  - **`X-Forwarded-*` is not honoured.** Trusting those headers without a
 *    configured list of trusted proxies lets a client dictate the scheme and
 *    host the application believes it is running under. F1 needs neither, since
 *    it builds no absolute URLs. Whoever needs `Location` headers behind a
 *    reverse proxy (F2) adds an explicit trusted-proxy configuration then.
 *  - **`$_FILES` is ignored.** No endpoint accepts an upload.
 */
final readonly class ServerRequestFactory
{
    public function __construct(private Psr17Factory $factory)
    {
    }

    public static function fromGlobals(): ServerRequestInterface
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;

        /** @var array<string, mixed> $query */
        $query = $_GET;

        /** @var array<string, mixed> $cookies */
        $cookies = $_COOKIE;

        return (new self(new Psr17Factory()))->create($server, $query, $cookies);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $cookies
     */
    public function create(array $server, array $query = [], array $cookies = []): ServerRequestInterface
    {
        $request = $this->factory
            ->createServerRequest(
                self::stringOr($server, 'REQUEST_METHOD', 'GET'),
                $this->uri($server),
                $server,
            )
            ->withProtocolVersion($this->protocolVersion($server))
            ->withQueryParams($query)
            ->withCookieParams($cookies)
            ->withBody($this->factory->createStreamFromFile('php://input', 'rb'));

        foreach (self::headers($server) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function uri(array $server): UriInterface
    {
        $https = self::stringOr($server, 'HTTPS', '');
        $secure = $https !== '' && strtolower($https) !== 'off';

        $scheme = $secure ? 'https' : self::stringOr($server, 'REQUEST_SCHEME', 'http');
        $authority = self::stringOr($server, 'HTTP_HOST', '');

        if ($authority === '') {
            $authority = self::stringOr($server, 'SERVER_NAME', 'localhost');
            $port = self::stringOr($server, 'SERVER_PORT', '');
            if ($port !== '' && $port !== '80' && $port !== '443') {
                $authority .= ':' . $port;
            }
        }

        $target = self::stringOr($server, 'REQUEST_URI', '/');
        if (!str_starts_with($target, '/')) {
            $target = '/' . $target;
        }

        return $this->factory->createUri($scheme . '://' . $authority . $target);
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    public static function headers(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[self::headerName(substr($key, 5))] = (string) $value;

                continue;
            }

            // CONTENT_TYPE and CONTENT_LENGTH arrive without the HTTP_ prefix.
            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[self::headerName($key)] = (string) $value;
            }
        }

        // Apache behind PHP-FPM drops the Authorization header; public/.htaccess
        // puts it back as an environment variable, which arrives with a
        // REDIRECT_ prefix after the internal rewrite to index.php. A client
        // cannot set that name: its headers always arrive as HTTP_*.
        $redirected = $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if (!isset($headers['Authorization']) && is_string($redirected) && $redirected !== '') {
            $headers['Authorization'] = $redirected;
        }

        return $headers;
    }

    private static function headerName(string $serverKey): string
    {
        return str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $serverKey))));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function protocolVersion(array $server): string
    {
        $protocol = self::stringOr($server, 'SERVER_PROTOCOL', 'HTTP/1.1');

        return str_starts_with($protocol, 'HTTP/') ? substr($protocol, 5) : '1.1';
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function stringOr(array $server, string $key, string $default): string
    {
        $value = $server[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
