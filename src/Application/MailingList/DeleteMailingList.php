<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\ListPolicy;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Log\LoggerInterface;

/**
 * `DELETE /lists/{listName}` — remove a mailing list (A5).
 *
 * Nothing is removed from the mail store, because nothing was ever created
 * there: a mailing list is an aliases entry the configuration management renders
 * from this table (E-4), not a mailbox. What the row's absence means is that the
 * **next** configuration run stops delivering the address — until then the list
 * still works, which is the opposite of the surprise every other delete in this
 * application has, and is exactly why the answer says so (U-59).
 *
 * The list is loaded before it is removed even though `DELETE` could be one
 * statement. That is what makes `If-Match` possible at all, and it is what makes
 * "no such list" a `404` rather than a `204` for a row that was never there —
 * the two are not the same answer to a client that is trying to find out what
 * exists.
 */
final readonly class DeleteMailingList
{
    public function __construct(
        private MailingListRepository $repository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AccessDenied
     * @throws MailingListFailure when the list is absent or `If-Match` is stale
     * @throws PersistenceError
     */
    public function handle(ActorContext $actor, string $name, ?string $ifMatch = null): void
    {
        ListPolicy::requireDelete($actor);

        $list = MailingListGuard::load($this->repository, $actor, $name);
        MailingListGuard::precondition($list, $ifMatch);

        if (!$this->repository->remove(MailingListGuard::addressOf($list))) {
            // Somebody removed it between the read and the delete. The client's
            // intention is satisfied either way, but it did not happen here, and
            // reporting it as done would put a line in the audit trail for a
            // write this process did not perform.
            throw MailingListFailure::notFound($name);
        }

        $this->logger->info('A mailing list was deleted.', [
            'admin' => $actor->username,
            'list' => $list->name->toString(),
            'list_address' => $list->address,
            'member_count' => $list->members->count(),
        ]);
    }
}
