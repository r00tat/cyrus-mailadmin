<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\MailingLists;

use Mailadmin\Application\MailingList\ListMailingLists;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /lists`.
 *
 * The query string becomes a {@see ListCriteria} and nothing else. There is no
 * path from a request value to a SQL fragment: `sort`, `order` and every filter
 * are resolved against the repository's allowlists, and a value outside them is
 * an error rather than a silent fall back to the default (`CLAUDE.md`, rule 2).
 *
 * {@see CriteriaError} is caught here and only here. The contract enumerates
 * every accepted `sort` value (U-20), so a request that reaches the allowlist
 * with an unknown one has already passed schema validation — which happens when
 * a client sends a filter parameter the schema does not describe. That is a
 * `422`, not a `500`.
 */
final readonly class ListMailingListsHandler implements ApiHandler
{
    public function __construct(
        private ListMailingLists $lists,
        private MailingListResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = MailingListResponder::actor($request);

        try {
            $criteria = ListCriteria::fromQuery($request->getQueryParams());

            return $this->responder->collection($this->lists->handle($actor, $criteria));
        } catch (CriteriaError $error) {
            return $this->responder->invalidCriteria($request, $error);
        }
    }
}
