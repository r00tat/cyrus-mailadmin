<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Domains;

use Mailadmin\Application\Domain\ListDomains;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /domains`.
 *
 * The scoping is not here, and that is the point: it is a predicate the store
 * builds from the same actor (U-45). A handler that filtered the page would
 * leave `meta.total` counting rows the caller may not see.
 *
 * {@see CriteriaError} is caught rather than allowed to become a `500`. The
 * contract enumerates `sort`, so the reachable case is a query parameter nobody
 * declared arriving as a filter — which the allowlist refuses on purpose, and
 * which is a client mistake worth reporting rather than an internal fault.
 */
final readonly class ListDomainsHandler implements ApiHandler
{
    public function __construct(
        private ListDomains $listDomains,
        private DomainResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        try {
            $page = $this->listDomains->handle($actor, ListCriteria::fromQuery($request->getQueryParams()));
        } catch (CriteriaError $error) {
            return $this->responder->badCriteria($request, $error);
        }

        return $this->responder->collection($request, $page);
    }
}
