<?php

declare(strict_types=1);

namespace Mailadmin\Http\Middleware;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Access\RolePermissions;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Http\Dispatcher;
use Mailadmin\Http\Problem\ErrorCode;
use Mailadmin\Http\Problem\ProblemDetails;
use Mailadmin\Http\Problem\ProblemResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Deny by default: no handler runs until the declared permission has been
 * checked against the actor's role (architecture 6).
 *
 * Runs immediately behind {@see AuthenticationMiddleware} in the dispatcher's
 * route-middleware chain, because both need the matched operation and neither
 * can see it before the router does (U-41).
 *
 * ## Three outcomes, and what separates them
 *
 *  - **`403`** — the role does not carry the operation's permission. About the
 *    operation, never about an object, so it reveals nothing.
 *  - **`404`** — a handler threw {@see ResourceNotVisible}: the object is in a
 *    domain the actor cannot see. Indistinguishable from a genuine `404`, which
 *    is the requirement (CLAUDE.md), so it reuses
 *    {@see ProblemDetails::notFound()} verbatim rather than building a lookalike
 *    that could drift.
 *  - **`500`** — the contract declares an `x-permission` this code does not
 *    know, or the chain is wired wrongly. Not a client error: the deployment is
 *    wrong. It cannot reach a running installation, because `RouteCompiler`
 *    refuses to compile such a contract — this is the second lock on the same
 *    door, and it is here so that the failure is loud rather than a silent
 *    allow.
 *
 * ## Why the policy exceptions are caught here
 *
 * A handler that lets {@see ResourceNotVisible} out of its use case gets the
 * right answer without writing a line, and there is exactly one place where the
 * `403`/`404` split is spelled. The alternative — every handler catching for
 * itself — is how the split becomes "mostly right": eight packages, one of them
 * writes `403` because it reads more naturally, and the enumeration hole is open
 * again. A handler that wants to add detail can still catch first.
 *
 * ## What is deliberately not done
 *
 * No per-object check. This middleware knows the operation, not the object —
 * the object is loaded by the handler. Scoping is the policies' job for a
 * single object and `Repository::scopedTo()`'s for a listing; putting half of it
 * here would produce a check that looks complete and is not.
 */
final readonly class AuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ProblemResponseFactory $problems,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $permission = self::declaredPermission($request);

        if ($permission->isPublic()) {
            return $handler->handle($request);
        }

        $actor = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);

        if (!$actor instanceof ActorContext) {
            // Authentication answers `401` for every non-public operation before
            // this runs, so getting here means the chain is not what
            // RouteMiddleware says it is. Refusing loudly beats a `403` that
            // would look like an ordinary refusal.
            throw new \LogicException(sprintf(
                'Authorisation ran without a verified actor for "%s". %s must run in front of it.',
                self::operationId($request),
                AuthenticationMiddleware::class,
            ));
        }

        if (!RolePermissions::of($actor->role)->includes($permission)) {
            return $this->forbid($request, AccessDenied::permissionMissing($actor, $permission));
        }

        try {
            return $handler->handle($request);
        } catch (AccessDenied $denied) {
            return $this->forbid($request, $denied);
        } catch (ResourceNotVisible $hidden) {
            return $this->hide($request, $hidden);
        }
    }

    /**
     * @throws \LogicException when the attribute is absent or names something
     *                         no {@see Permission} covers
     */
    private static function declaredPermission(ServerRequestInterface $request): Permission
    {
        $declared = $request->getAttribute(Dispatcher::ATTRIBUTE_PERMISSION);

        if (!is_string($declared)) {
            throw new \LogicException(
                'Authorisation ran without a routed operation. It belongs in the dispatcher’s '
                . 'route-middleware chain, not in the outer chain.',
            );
        }

        $permission = Permission::tryFrom($declared);

        if ($permission === null) {
            throw new \LogicException(sprintf(
                'Operation "%s" declares the permission "%s", which is not in the Permission enum. '
                . 'Add it there, or fix the contract — an unknown permission is never granted.',
                self::operationId($request),
                $declared,
            ));
        }

        return $permission;
    }

    /**
     * `warning`, not `info`: an authenticated administrator reaching for
     * something their role does not have is either a client bug or somebody
     * trying. webcyradm logged the same event as "SECURITY VIOLATION" and it was
     * the one thing in that file worth keeping.
     */
    private function forbid(ServerRequestInterface $request, AccessDenied $denied): ResponseInterface
    {
        $this->logger->warning('A request was refused: the role does not carry the permission.', [
            'admin' => $denied->username,
            'permission' => $denied->permission->value,
            'operation_id' => self::operationId($request),
            'http_method' => $request->getMethod(),
            'http_path' => $request->getUri()->getPath(),
        ]);

        return $this->problems->create(new ProblemDetails(
            status: 403,
            code: ErrorCode::FORBIDDEN,
            title: 'Forbidden',
            detail: 'This account may not perform this operation.',
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
        ));
    }

    /**
     * `info`: a domain master following a stale bookmark into a domain they no
     * longer hold is ordinary, and a level that shouts here would make the
     * `warning` above worthless.
     *
     * The response is the plain `404`. Nothing about the object, nothing about
     * scope — that is what makes it the same answer as for an object that never
     * existed.
     */
    private function hide(ServerRequestInterface $request, ResourceNotVisible $hidden): ResponseInterface
    {
        $this->logger->info('A request was answered with 404: the object is outside the actor’s scope.', [
            'admin' => $hidden->username,
            'reason' => $hidden->getMessage(),
            'operation_id' => self::operationId($request),
            'http_path' => $request->getUri()->getPath(),
        ]);

        return $this->problems->create(ProblemDetails::notFound(
            instance: $request->getUri()->getPath(),
            requestId: self::requestId($request),
        ));
    }

    private static function operationId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(Dispatcher::ATTRIBUTE_OPERATION_ID);

        return is_string($value) ? $value : '(unrouted)';
    }

    private static function requestId(ServerRequestInterface $request): ?string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
