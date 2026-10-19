<?php

declare(strict_types=1);

namespace Mailadmin\Http\Handler\Maintenance;

use Mailadmin\Application\Maintenance\ListRepairs;
use Mailadmin\Application\Maintenance\RepairEntry;
use Mailadmin\Http\Handler\ApiHandler;
use Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /maintenance/repairs`. Newest first unless `order` says otherwise — the
 * contract's default does not reach the parser, so it is applied here, as for
 * the audit trail (U-65).
 */
final readonly class ListRepairsHandler implements ApiHandler
{
    public function __construct(
        private ListRepairs $repairs,
        private MaintenanceResponder $responder,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->responder->actor($request);

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();

        try {
            $criteria = ListCriteria::fromQuery($query);

            if (!array_key_exists('order', $query)) {
                $criteria = $criteria->withSort($criteria->sort, ListRepairs::defaultOrder());
            }

            $page = $this->repairs->handle($actor, $criteria);
        } catch (CriteriaError $error) {
            return $this->responder->badCriteria($request, $error);
        }

        return $this->responder->json(200, [
            'items' => array_map(
                static fn (RepairEntry $entry): array => $entry->toRepresentation(),
                $page->items,
            ),
            'meta' => $page->meta(),
        ]);
    }
}
