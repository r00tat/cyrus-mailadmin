<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\ListPolicy;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Log\LoggerInterface;

/**
 * `POST /lists` — a new mailing list (A5).
 *
 * ## No mail store, and therefore no ordering problem
 *
 * This is the one A-package whose write touches nothing but the database. A
 * mailing list has no mailbox: the address is delivered by an aliases file that
 * the configuration management renders from this table (E-4), so there is no
 * Cyrus half that could succeed while the database half fails, and none of
 * architecture 8.1's ordering rules apply.
 *
 * What replaces them is the honest statement that the change is **not yet in
 * effect**. Until the next configuration run the new address bounces, and an
 * administrator who is not told that looks for the fault in the wrong place.
 * Every write in this package therefore answers with that fact (U-59).
 *
 * ## Every member is new here
 *
 * `MemberList::none()->withMembers()` treats the whole list as additions, so all
 * of them go through the address check — which is a security rule, not a
 * validation nicety: the column is rendered into an aliases file where
 * `|command` is a command (see {@see InvalidMailingList::memberNotAnAddress()}).
 */
final readonly class CreateMailingList
{
    public function __construct(
        private MailingListRepository $repository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $members
     *
     * @throws AccessDenied
     * @throws MailingListFailure when a value is refused or the name is taken
     * @throws PersistenceError
     */
    public function handle(
        ActorContext $actor,
        string $name,
        string $tag,
        string $address,
        array $members,
    ): MailingList {
        ListPolicy::requireWrite($actor);

        $list = self::assemble($name, $tag, $address, $members);

        // The database has no unique index, so the address — and its list key,
        // which is what the pipe script looks the list up by — is checked here.
        $address = MailingListGuard::addressOf($list);

        if ($this->repository->find($address) !== null
            || $this->repository->existsWithKey(ListDelivery::listKey($address))) {
            throw MailingListFailure::alreadyExists($name);
        }

        $this->repository->add($list);

        // Until `mailadmin_audit` exists (S4) this line is the record. The audit
        // entry belongs at exactly this point; the member addresses do not go
        // into it, because a log is not a copy of the table.
        $this->logger->info('A mailing list was created.', [
            'admin' => $actor->username,
            'list' => $list->name->toString(),
            'list_address' => $list->address,
            'member_count' => $list->members->count(),
        ]);

        return $list;
    }

    /**
     * @param list<string> $members
     *
     * @throws MailingListFailure
     */
    private static function assemble(string $name, string $tag, string $address, array $members): MailingList
    {
        try {
            return MailingList::create($name, $tag, $address, $members);
        } catch (InvalidMailingList $error) {
            throw MailingListFailure::rejected($error);
        }
    }
}
