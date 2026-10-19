<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Auth;

use Mailadmin\Application\Auth\AuthenticationFailed;
use Mailadmin\Application\Auth\IssuedSession;
use Mailadmin\Application\Auth\RefreshDelivery;
use Mailadmin\Http\Middleware\RequestIdMiddleware;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Http\RefreshCookie;
use Mailadmin\Runtime\Clock;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Renders what the sign-in and refresh endpoints return.
 *
 * Both produce the same two shapes — a session or a failure — and both have to
 * get the cookie handling exactly right, so it is written once here rather than
 * twice in two handlers that would drift.
 *
 * ## The refresh token is in exactly one place
 *
 * With {@see RefreshDelivery::Cookie} the body carries **no** `refresh_token`
 * member at all, and with {@see RefreshDelivery::Body} no cookie is set. Sending
 * both would defeat the point: a script that can read the response body would
 * then have the twelve-hour credential the HttpOnly cookie exists to keep away
 * from it (E-15).
 *
 * ## `Cache-Control: no-store`
 *
 * Set here as well as by the security headers, because this is the response
 * that carries credentials and a change to the shared middleware must not be
 * able to make it cacheable by accident.
 */
final readonly class SessionResponder
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private ProblemResponseFactory $problems,
        private RefreshCookie $cookie,
        private Clock $clock,
    ) {
    }

    public function session(
        ServerRequestInterface $request,
        IssuedSession $session,
        RefreshDelivery $delivery,
    ): ResponseInterface {
        $now = $this->clock->now();

        $document = [
            'access_token' => $session->accessToken->value,
            'token_type' => 'Bearer',
            'expires_in' => $session->accessToken->expiresIn($now),
        ];

        if (!$delivery->isCookie()) {
            $document['refresh_token'] = $session->refreshToken->value;
        }

        $response = $this->json(200, $document);

        if ($delivery->isCookie()) {
            $response = $this->cookie->attachTo(
                $response,
                $session->refreshToken->value,
                $session->refreshExpiresIn($now),
            );
        }

        return $response;
    }

    /**
     * A refusal, with the cookie cleared.
     *
     * Clearing it on **every** failure is deliberate. A browser holding a
     * refresh token that has just been revoked — or that was never valid — would
     * otherwise keep sending it, and each attempt would look like another reuse.
     * The one exception would be a rate-limited sign-in, where the cookie is not
     * implicated at all; it is cleared there too, because a `429` on the sign-in
     * page means the session is over in any case.
     */
    public function failure(ServerRequestInterface $request, AuthenticationFailed $failure): ResponseInterface
    {
        $response = $this->problems->create(new ProblemDetails(
            status: $failure->status,
            code: $failure->errorCode,
            title: self::titleFor($failure->status),
            detail: self::detailFor($failure->errorCode),
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
        ));

        if ($failure->retryAfterSeconds > 0) {
            $response = $response->withHeader('Retry-After', (string) $failure->retryAfterSeconds);
        }

        return $this->cookie->clearOn($response);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function json(int $status, array $document): ResponseInterface
    {
        $body = json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $this->responses
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($body));
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            401 => 'Unauthorized',
            429 => 'Too Many Requests',
            default => 'Bad Request',
        };
    }

    /**
     * English fallback text, not a contract (E-16). None of these says whether
     * an account exists.
     */
    private static function detailFor(string $code): string
    {
        return match ($code) {
            \Mailadmin\Application\Auth\AuthErrorCode::INVALID_CREDENTIALS
                => 'The user name or the password is not correct.',
            \Mailadmin\Application\Auth\AuthErrorCode::REFRESH_INVALID
                => 'The session could not be renewed. Sign in again.',
            \Mailadmin\Application\Auth\AuthErrorCode::REFRESH_REUSED
                => 'This session was ended because a refresh token was presented twice. Sign in again.',
            default => 'Too many attempts. Try again later.',
        };
    }

    private static function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
