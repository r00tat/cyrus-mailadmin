<?php

declare(strict_types=1);

namespace Mailadmin\Http\Middleware;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\AdminDirectory;
use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Mailadmin\Infrastructure\Security\TokenClaims;
use Mailadmin\Infrastructure\Security\TokenDenylist;
use Mailadmin\Infrastructure\Security\TokenRejected;
use Mailadmin\Infrastructure\Security\TokenVerifier;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Establishes *who* is making this request. What they may do is S3's question.
 *
 * Runs in the dispatcher's route-middleware slot, so it can read the operation's
 * `x-permission` — see {@see Dispatcher} for why that placement is the only one
 * that works.
 *
 * ## A public operation is not touched at all
 *
 * `x-permission: public` returns immediately, without looking at the
 * `Authorization` header. That is deliberate rather than lazy: a browser holding
 * a stale token in `localStorage` will send it with the very request meant to
 * replace it, and rejecting `POST /auth/token` because the old token expired
 * would make signing in impossible exactly when it is needed.
 *
 * ## Four things are checked, in this order
 *
 *  1. **The signature, `exp`, `iss` and `aud`** — {@see TokenVerifier}.
 *  2. **The denylist.** One indexed read. This is what makes signing out mean
 *     now rather than within the hour (architecture 5.4).
 *  3. **The administrator still exists**, and their scope is read fresh. The
 *     domain scope is deliberately not in the token (architecture 5.3), so this
 *     read has to happen anyway — and it answers the question of the removed
 *     account for free, without a schema change and without a status column
 *     `adminuser` does not have.
 *  4. **The role still matches the token.** A superuser demoted to domain master
 *     would otherwise keep superuser rights for the rest of the hour. Mismatch
 *     is a `401`: the token was issued under an assumption that no longer holds,
 *     and the client's correct reaction is to get a new one.
 *
 * ## Everything fails the same way
 *
 * Every rejection is `401` with `request.unauthenticated`. The reason is
 * distinguished in the **log** and never in the response: telling a caller
 * whether the signature was wrong, the token expired or the account is gone
 * tells them how close they are. `WWW-Authenticate: Bearer` is sent because RFC
 * 9110 requires it on a `401`, and it carries no detail beyond the scheme.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    /** The verified {@see ActorContext}. S3 and every handler read it from here. */
    public const string ATTRIBUTE_ACTOR = 'mailadmin.actor';

    /** The verified {@see TokenClaims}. Sign-out needs the `jti`; nothing else does. */
    public const string ATTRIBUTE_CLAIMS = 'mailadmin.token_claims';

    /** The `x-permission` value that means "no token required" (openapi/README.md, 5). */
    public const string PUBLIC_PERMISSION = 'public';

    public function __construct(
        private TokenVerifier $verifier,
        private TokenDenylist $denylist,
        private AdminDirectory $directory,
        private ProblemResponseFactory $problems,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isPublic($request)) {
            return $handler->handle($request);
        }

        try {
            $claims = $this->authenticate($request);
        } catch (TokenRejected $rejection) {
            return $this->refuse($request, $rejection);
        }

        $actor = $this->directory->findActor($claims->subject);

        if ($actor === null) {
            return $this->refuse($request, TokenRejected::noSuchAdministrator($claims->subject));
        }

        if ($actor->role !== $claims->role) {
            return $this->refuse($request, TokenRejected::roleChanged($claims->subject));
        }

        return $handler->handle(
            $request
                ->withAttribute(self::ATTRIBUTE_CLAIMS, $claims)
                ->withAttribute(self::ATTRIBUTE_ACTOR, $actor),
        );
    }

    /**
     * @throws TokenRejected
     */
    private function authenticate(ServerRequestInterface $request): TokenClaims
    {
        $claims = $this->verifier->verify(self::bearerTokenOf($request));

        if ($this->denylist->isDenied($claims)) {
            throw TokenRejected::revoked($claims->subject);
        }

        return $claims;
    }

    /**
     * @throws TokenRejected when there is no usable `Authorization: Bearer …`
     */
    private static function bearerTokenOf(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '') {
            throw TokenRejected::missing();
        }

        // Case-insensitive on the scheme, as RFC 9110 requires; strict about the
        // single space, because anything else is not a bearer credential.
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            throw TokenRejected::malformed();
        }

        return $matches[1];
    }

    private function isPublic(ServerRequestInterface $request): bool
    {
        return $request->getAttribute(Dispatcher::ATTRIBUTE_PERMISSION) === self::PUBLIC_PERMISSION;
    }

    private function refuse(ServerRequestInterface $request, TokenRejected $rejection): ResponseInterface
    {
        // `info`, not `warning`: an expired token on a long-open browser tab is
        // the ordinary case, and a log level that cries wolf on it teaches
        // people to ignore the level.
        $this->logger->info('A request was not authenticated.', [
            'reason' => $rejection->reason,
            'detail' => $rejection->getMessage(),
            'http_path' => $request->getUri()->getPath(),
        ]);

        return $this->problems
            ->create(new ProblemDetails(
                status: 401,
                code: ErrorCode::UNAUTHENTICATED,
                title: 'Unauthorized',
                detail: 'This request needs a valid access token.',
                instance: $request->getUri()->getPath(),
                requestId: self::requestId($request),
            ))
            ->withHeader('WWW-Authenticate', 'Bearer');
    }

    private static function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
