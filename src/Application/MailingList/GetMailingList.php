<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\ListPolicy;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\PersistenceError;

/**
 * `GET /lists/{listName}` — one mailing list (A5).
 *
 * The order is permission first, existence second, and it is not
 * interchangeable: an actor whose role may not read lists at all learns nothing
 * from a `403`, while checking existence first would answer `404` for a list
 * that is there and `403`… for nothing, which is the leak the other way round
 * (`Domain\Policy\Guard`).
 */
final readonly class GetMailingList
{
    public function __construct(private MailingListRepository $repository)
    {
    }

    /**
     * @throws AccessDenied
     * @throws MailingListFailure when there is no such list
     * @throws PersistenceError
     */
    public function handle(ActorContext $actor, string $name): MailingList
    {
        ListPolicy::requireRead($actor);

        return MailingListGuard::load($this->repository, $actor, $name);
    }
}
