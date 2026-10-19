<?php

declare(strict_types=1);

namespace Mailadmin\Application\Audit;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Criteria\SortDirection;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * `GET /audit`: the trail, filtered and paged.
 *
 * A thin use case, and it is worth the file for two reasons that are not
 * ceremony. It is where the {@see ActorContext} enters — CLAUDE.md asks every use
 * case to take one first, and a handler calling the reader directly would be the
 * one place in the application where nothing does. And it is where the default
 * ordering is decided, which is a decision and not a detail: the interesting end
 * of an audit trail is the recent one, so an unqualified request answers newest
 * first (U-65).
 *
 * The reading itself is **not** audited. S4 is explicit — reads are not recorded
 * — and a trail that recorded its own inspection would grow by one row per look
 * at it, which is how a table becomes unreadable by being read.
 */
final readonly class ListAuditTrail
{
    public function __construct(private AuditReader $entries)
    {
    }

    /**
     * @return Page<AuditRecord>
     */
    public function handle(ActorContext $actor, ListCriteria $criteria): Page
    {
        return $this->entries->list($actor, $criteria);
    }

    /**
     * The ordering an unqualified request gets.
     *
     * Applied by the handler when the query names no `order`, because the
     * contract's default is a piece of documentation and does not reach
     * {@see ListCriteria::fromQuery()} — which defaults to ascending, the right
     * default everywhere except here.
     */
    public static function defaultOrder(): SortDirection
    {
        return SortDirection::Descending;
    }
}
