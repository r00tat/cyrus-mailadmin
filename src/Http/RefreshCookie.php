<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The one cookie this application sets (E-15, architecture 5.2).
 *
 * Four attributes, and each of them is load-bearing:
 *
 * | Attribute | Why |
 * |---|---|
 * | `HttpOnly` | The whole point. The access token is reachable by script in `localStorage`; the refresh token must not be, or a stolen token's window grows from an hour to twelve. |
 * | `Secure` | mailadmin sends HSTS unconditionally (architecture 5.7) and is HTTPS-only by design. A refresh cookie that would also travel over plain HTTP is the one that leaks. |
 * | `SameSite=Strict` | `POST /auth/refresh` is the single endpoint a browser reaches with a cookie instead of a bearer header, so it is the single endpoint with a CSRF surface (architecture 7.3). |
 * | narrow `Path` | The cookie is sent to `/auth/*` and nowhere else — not to any resource endpoint, not to the SPA's own assets. |
 *
 * `Max-Age` is set from the family deadline rather than left as a session
 * cookie, so a browser stops sending a token at the moment it stops working.
 *
 * **`Secure` is not made conditional on the request scheme.** It would be one
 * line, and it would mean that anyone who reached the application over plain
 * HTTP — a misconfigured proxy, a forgotten redirect — silently got the weaker
 * cookie. Refusing to work over HTTP is the more useful failure.
 */
final readonly class RefreshCookie
{
    public const string NAME = 'mailadmin_refresh';

    /** Appended to the mount prefix. Covers `/auth/refresh` and `/auth/logout`. */
    private const string PATH_SUFFIX = '/api/v1/auth';

    public function __construct(private string $basePath = '')
    {
    }

    /**
     * The value the browser sent, or `null`.
     */
    public function readFrom(ServerRequestInterface $request): ?string
    {
        /** @var mixed $value */
        $value = $request->getCookieParams()[self::NAME] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    public function attachTo(ResponseInterface $response, string $token, int $maxAgeSeconds): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            $this->render($token, max(0, $maxAgeSeconds)),
        );
    }

    /**
     * Overwrite the cookie with an empty, already-expired one.
     *
     * Both `Max-Age=0` and a past `Expires` are sent: `Max-Age` is what every
     * current browser honours, `Expires` is what an intermediary that predates
     * it understands. Setting only one is the usual reason a "cleared" cookie
     * comes back.
     */
    public function clearOn(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            $this->render('', 0) . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT',
        );
    }

    public function path(): string
    {
        return rtrim($this->basePath, '/') . self::PATH_SUFFIX;
    }

    private function render(string $value, int $maxAgeSeconds): string
    {
        return sprintf(
            '%s=%s; Max-Age=%d; Path=%s; HttpOnly; Secure; SameSite=Strict',
            self::NAME,
            // The token is base64url, so nothing here needs escaping. Encoding
            // anyway costs nothing and means a future change to the token
            // alphabet cannot produce a cookie that silently truncates.
            rawurlencode($value),
            $maxAgeSeconds,
            $this->path(),
        );
    }
}
