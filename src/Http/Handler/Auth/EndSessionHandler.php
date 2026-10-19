<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Auth;

use Mailadmin\Application\Auth\SignOut;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Http\RefreshCookie;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /auth/logout`.
 *
 * The claims come from the authentication middleware, which has already
 * verified the token — that is why this handler can be certain there is one and
 * why the missing case is a `LogicException` rather than a `401`. A `401` there
 * would be a lie about what went wrong: the operation is not public, so an
 * unauthenticated request never reaches this class.
 *
 * `204` with no body, and the refresh cookie cleared whether or not one was
 * sent. Clearing unconditionally costs a header and means a browser cannot be
 * left holding a token whose family has just been revoked.
 */
final readonly class EndSessionHandler implements ApiHandler
{
    public function __construct(
        private SignOut $signOut,
        private RefreshCookie $cookie,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_CLAIMS);

        if (!$claims instanceof TokenClaims) {
            throw new \LogicException(
                'EndSessionHandler ran without verified token claims. The authentication '
                . 'middleware must be in the dispatcher’s route-middleware chain.',
            );
        }

        $this->signOut->handle($claims, $this->cookie->readFrom($request));

        return $this->cookie->clearOn(
            $this->responses->createResponse(204)->withHeader('Cache-Control', 'no-store'),
        );
    }
}
