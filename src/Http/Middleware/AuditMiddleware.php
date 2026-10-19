<?php

declare(strict_types=1);

namespace Mailadmin\Http\Middleware;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditRequestContext;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Http\ClientAddress;
use Mailadmin\Http\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Two duties, and they share this position in the chain for the same reason.
 *
 * **1. It binds the audit context.** The client address and the request id
 * belong in every entry (architecture 13) and are knowable only here. Putting
 * them into {@see AuditRequestContext} rather than into every use-case signature
 * is U-64; this is the one place that fills it, and the `finally` is what keeps a
 * long-running process from letting one request's address leak into the next.
 *
 * **2. It records a refusal.** A `403` never reaches a use case —
 * {@see AuthorizationMiddleware} answers it before the handler runs — so the one
 * entry S4 most wants ("a refused deletion attempt is security-relevant") cannot
 * be written where the others are. It is written here, from the response.
 *
 * ## Why it sits between authentication and authorisation
 *
 * It has to be **inside** authentication, or the actor attribute would not be on
 * the request it sees: PSR-15 hands the modified request downwards only. It has
 * to be **outside** authorisation, or it would not see the `403` that
 * authorisation produces. That leaves exactly one position, which is why
 * `RouteMiddleware` names it there.
 *
 * ## What it does not record
 *
 *  - **Successful requests.** Those are recorded by the use case that changed
 *    something, where the before and after states are known. A middleware
 *    recording them as well would double every entry.
 *  - **`401`.** Nobody is authenticated, so there is no actor and nothing to say
 *    beyond what the sign-in path already records.
 *  - **`404` for a foreign object.** That is deliberately indistinguishable from
 *    an object that never existed (U-44); an audit row saying otherwise would
 *    re-open, in the trail, the leak the response closes. A domain master
 *    following a stale bookmark is in the application log, at `info`.
 *
 * A refused **read** is recorded, and that is not a contradiction of "reads are
 * not audited": what is recorded is the attempt, not the data. A refusal is
 * rare, and a burst of them is the thing worth finding.
 */
final readonly class AuditMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuditRequestContext $context,
        private AuditWriter $audit,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context->bind(self::requestId($request), ClientAddress::of($request));

        try {
            $response = $handler->handle($request);

            if ($response->getStatusCode() === 403) {
                $this->recordRefusal($request);
            }

            return $response;
        } finally {
            $this->context->clear();
        }
    }

    /**
     * The permission the operation declared **is** the action, because the two
     * are spelled alike on purpose: `<resource>.<verb>` in `openapi/README.md`
     * (5) and `<object>.<verb>` in {@see \Mailadmin\Application\Audit\AuditAction}.
     * A refusal of `domain.delete` is therefore found by the same search as the
     * deletions that went through.
     */
    private function recordRefusal(ServerRequestInterface $request): void
    {
        $actor = $request->getAttribute(AuthenticationMiddleware::ATTRIBUTE_ACTOR);
        $permission = Permission::tryFrom((string) $request->getAttribute(Dispatcher::ATTRIBUTE_PERMISSION));

        if (!$actor instanceof ActorContext || $permission === null || $permission->isPublic()) {
            // A `403` without an actor or without a grantable permission cannot
            // come from the authorisation middleware, and this class will not
            // invent an entry for something it does not understand.
            return;
        }

        try {
            $this->audit->record(AuditEntry::refusal(
                $actor,
                $permission->value,
                $permission->resource(),
                reason: sprintf(
                    '%s %s: the role does not hold %s',
                    $request->getMethod(),
                    $request->getUri()->getPath(),
                    $permission->value,
                ),
            ));
        } catch (Throwable $error) {
            // The refusal itself stands. Turning a `403` into a `500` because
            // the trail could not be written would answer the wrong question,
            // and it would hand anyone who can trigger a write failure a way to
            // make refusals look like server faults.
            $this->logger->error('Could not record a refusal in the audit trail.', [
                'admin' => $actor->username,
                'permission' => $permission->value,
                'reason' => $error->getMessage(),
            ]);
        }
    }

    private static function requestId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($value) ? $value : '';
    }
}
