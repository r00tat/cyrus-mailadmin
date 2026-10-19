<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Auth;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\RolePermissions;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * `GET /auth/me`.
 *
 * Answers from the {@see ActorContext} the middleware built out of the
 * **database**, not from the token's claims. That is the whole value of the
 * endpoint: the domain scope is read per request (architecture 5.3), so a
 * withdrawn assignment shows up here immediately, while a token minted an hour
 * ago would still describe the old state.
 *
 * `expires_at` does come from the token, because it is a fact about the
 * credential the client is holding rather than about the administrator. A client
 * uses it to decide when to refresh without decoding the JWT itself.
 *
 * The domain list is empty for a superuser and carries no meaning there — the
 * contract says so, because an empty list for a *domain master* would mean the
 * opposite, and a client that confused the two would show an administrator
 * everything they are not allowed to see.
 *
 * `permissions` (S3) is derived from the role rather than stored: it is what
 * {@see RolePermissions} would answer for this request, so a client and the
 * authorisation middleware cannot disagree. It is sent so that the frontend can
 * render, not so that it can decide — every request is checked again regardless
 * of what the client believed.
 */
final readonly class GetCurrentSessionHandler implements ApiHandler
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);
        $claims = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_CLAIMS);

        if (!$actor instanceof ActorContext || !$claims instanceof TokenClaims) {
            throw new \LogicException(
                'GetCurrentSessionHandler ran without a verified actor. The authentication '
                . 'middleware must be in the dispatcher’s route-middleware chain.',
            );
        }

        $body = json_encode(
            [
                'username' => $actor->username,
                'role' => $actor->role->value,
                'permissions' => RolePermissions::of($actor->role)->toStrings(),
                'domains' => $actor->isSuperuser() ? [] : $actor->scope->domainNames(),
                'expires_at' => $claims->expiresAt->format('Y-m-d\TH:i:s\Z'),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $this->responses
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($body));
    }
}
