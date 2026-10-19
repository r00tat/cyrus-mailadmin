<?php

declare(strict_types=1);

namespace Mailadmin\Application\Domain;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\DomainPolicy;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\Page;

/**
 * `GET /domains`.
 *
 * Two lines, and both of them matter.
 *
 * {@see DomainPolicy::requireList()} checks the permission and **nothing else**.
 * There is no single domain to check against, and calling `requireRead()` per
 * row would answer `404` for a domain master's own list.
 *
 * The narrowing is the query's: the store builds the predicate from the same
 * actor through `Repository::scopedTo()` (U-45). That is not an implementation
 * detail this use case is free to duplicate — a filter applied here would leave
 * `meta.total` counting rows the caller may not see, and paging would show a
 * domain master empty pages.
 *
 * The policy check is repeated even though `AuthorizationMiddleware` already
 * refused a role without `domain.read`. Repeating it costs a set lookup and
 * makes the use case safe to call from a command-line tool, where there is no
 * middleware at all.
 */
final readonly class ListDomains
{
    public function __construct(private DomainStore $store)
    {
    }

    /**
     * @return Page<DomainSummary>
     *
     * @throws \Mailadmin\Domain\Policy\AccessDenied
     * @throws \Mailadmin\Infrastructure\Persistence\Criteria\CriteriaError
     * @throws \Mailadmin\Infrastructure\Persistence\PersistenceError
     */
    public function handle(ActorContext $actor, ListCriteria $criteria): Page
    {
        DomainPolicy::requireList($actor);

        return $this->store->list($actor, $criteria);
    }
}
