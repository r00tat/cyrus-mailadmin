<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * `GET /maintenance/repairs`: the queue, filtered and paged, newest first by
 * default — the interesting end of a queue is the recent one, as with the audit
 * trail (U-65).
 *
 * `maintenance.read` is superuser-only, and the route enforces it; there is no
 * per-domain narrowing because an entry may name no domain at all.
 */
final readonly class ListRepairs
{
    public function __construct(private RepairLedger $ledger)
    {
    }

    /**
     * @return Page<RepairEntry>
     */
    public function handle(ActorContext $actor, ListCriteria $criteria): Page
    {
        return $this->ledger->list($criteria);
    }

    public static function defaultOrder(): SortDirection
    {
        return SortDirection::Descending;
    }
}
