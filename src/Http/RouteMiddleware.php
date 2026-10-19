<?php

declare(strict_types=1);

namespace Mailadmin\Http;

use Mailadmin\Http\Middleware\AuditMiddleware;
use Mailadmin\Http\Middleware\AuthenticationMiddleware;
use Mailadmin\Http\Middleware\AuthorizationMiddleware;
use Psr\Http\Server\MiddlewareInterface;

/**
 * The middleware that runs **after** a route has been matched, in order.
 *
 * Its counterpart is the outer chain in `config/di/core.php`, which runs before
 * anything is routed. The split is explained in {@see Dispatcher}: whether a
 * request needs a token is a property of the matched operation, and nothing
 * ahead of the router knows which operation that is.
 *
 * ## Why a class and not a container array
 *
 * The order of these is a decision, and decisions want one visible home. A
 * container entry that several package files appended to would spread the order
 * across those files and make it depend on the order the container merges them
 * in — which is alphabetical by file name, and therefore an accident.
 *
 * So a package that adds a route middleware adds a constructor parameter here,
 * in the position it belongs. PHP-DI builds the rest.
 *
 * ## The order
 *
 *  1. **Authentication** (S2) — establishes *who*. A public operation passes
 *     straight through; everything else needs a verified token.
 *  2. **Audit** (S4) — binds the request id and the client address to the audit
 *     context, and records a refusal on the way back out. Its position is not a
 *     preference: it needs the actor attribute, which only exists *inside*
 *     authentication, and it needs to see the `403`, which only exists *outside*
 *     authorisation. That is one possible slot, and this is it.
 *  3. **Authorisation** (S3) — decides whether that person may perform this
 *     operation, reading `x-permission` from the request attribute. It goes
 *     after authentication for the obvious reason and before the handler for
 *     the same one.
 *
 * Authorisation is also the innermost on the way **back out**, which is what lets
 * it turn a policy exception from a handler into the right refusal:
 * {@see AuthorizationMiddleware} catches `AccessDenied` and `ResourceNotVisible`
 * as they pass. A middleware appended after it would sit between it and the
 * handler and would have to know not to swallow them — so anything added later
 * belongs *before* authorisation unless it has a reason to be inside the catch.
 */
final readonly class RouteMiddleware
{
    public function __construct(
        private AuthenticationMiddleware $authentication,
        private AuditMiddleware $audit,
        private AuthorizationMiddleware $authorization,
    ) {
    }

    /**
     * @return list<MiddlewareInterface>
     */
    public function all(): array
    {
        return [$this->authentication, $this->audit, $this->authorization];
    }
}
