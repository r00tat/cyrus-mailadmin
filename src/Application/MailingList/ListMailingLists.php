<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\ListPolicy;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\Page;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * `GET /lists` — one page of mailing lists (A5).
 *
 * Two lines, and both of them are the point.
 *
 * {@see ListPolicy::requireRead()} decides whether this kind of operation is
 * open to the actor at all, and the exception it throws is **not caught here**:
 * `AuthorizationMiddleware` turns it into `403`, so that the 403/404 split is
 * spelled in one place rather than in every package (U-44).
 *
 * The repository then narrows the query with `Repository::scopedTo()` (U-45):
 * a domain master sees the lists whose address is in one of their domains
 * (spec 4.3, "Rechte").
 */
final readonly class ListMailingLists
{
    public function __construct(private MailingListRepository $repository)
    {
    }

    /**
     * @return Page<MailingList>
     *
     * @throws AccessDenied
     * @throws PersistenceError
     */
    public function handle(ActorContext $actor, ListCriteria $criteria): Page
    {
        ListPolicy::requireRead($actor);

        return $this->repository->list($actor, $criteria);
    }
}
