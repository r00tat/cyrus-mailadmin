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
 * `PATCH /lists/{listName}` — change a mailing list (A5).
 *
 * ## The rule this class exists to keep
 *
 * **A list that is saved unchanged is written back byte for byte.** `lists` is
 * written by an external tool and read by the configuration management (E-4),
 * and the exact separator the existing rows use was never captured — so this
 * package must never re-punctuate a column it was not asked to change.
 *
 * Two things together make that true, and neither is enough alone:
 *
 *  - a field the request did not mention is `null` in {@see MailingListChanges}
 *    and is not touched;
 *  - a `members` value that parses to the sequence already stored returns the
 *    **same** {@see \Mailadmin\Domain\MailingList\MemberList} object, so the
 *    original text — spacing, separator, even a `NULL` — is what goes back into
 *    the column.
 *
 * The write therefore runs unconditionally. There is no "has anything changed"
 * branch, because the answer would have to be computed from the same comparison
 * that already produces the unchanged value, and a branch is a thing that can be
 * wrong; an `UPDATE` that writes the identical bytes is not.
 *
 * ## Concurrency
 *
 * `If-Match` is accepted, not demanded (architecture 7.1). Sent and stale, the
 * answer is `412`; absent, the write proceeds. Demanding it would mean a `428`
 * for every client that has not implemented it, which is a decision for the
 * whole API and not for one package.
 */
final readonly class UpdateMailingList
{
    public function __construct(
        private MailingListRepository $repository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AccessDenied
     * @throws MailingListFailure when the list is absent, `If-Match` is stale, or a value is refused
     * @throws PersistenceError
     */
    public function handle(
        ActorContext $actor,
        string $name,
        MailingListChanges $changes,
        ?string $ifMatch = null,
    ): MailingList {
        ListPolicy::requireWrite($actor);

        $stored = MailingListGuard::load($this->repository, $actor, $name);
        MailingListGuard::precondition($stored, $ifMatch);

        $updated = self::apply($stored, $changes);

        $this->write($stored, $updated);

        $this->logger->info('A mailing list was changed.', [
            'admin' => $actor->username,
            'list' => $updated->name->toString(),
            'changed' => self::changedFields($changes),
            'member_count' => $updated->members->count(),
        ]);

        return $updated;
    }

    /**
     * The address is the identity, so a changed address is a create and a
     * delete — with the same free-address check a create makes, ignoring the
     * list's own old row. An unchanged address (compared exactly, so a change
     * of case counts) is an `UPDATE` of the row.
     *
     * The new row goes in first: a leftover duplicate is harmless, lost members
     * are not. The one exception is a change of case only — `remove()` matches
     * the address case-insensitively like the column does, so it would take the
     * new row with it; there the old row has to go first.
     *
     * @throws MailingListFailure when the new address or its list key is taken
     */
    private function write(MailingList $stored, MailingList $updated): void
    {
        $before = MailingListGuard::addressOf($stored);
        $after = MailingListGuard::addressOf($updated);

        if ($before->toString() === $after->toString()) {
            $this->repository->replace($updated);

            return;
        }

        if ($before->toLowerCase()->equals($after->toLowerCase())) {
            $this->repository->remove($before);
            $this->repository->add($updated);

            return;
        }

        $sameKey = ListDelivery::listKey($before->toLowerCase()) === ListDelivery::listKey($after->toLowerCase());

        if ($this->repository->find($after) !== null
            || (!$sameKey && $this->repository->existsWithKey(ListDelivery::listKey($after)))) {
            throw MailingListFailure::alreadyExists($updated->name->toString());
        }

        $this->repository->add($updated);
        $this->repository->remove($before);
    }

    /**
     * @throws MailingListFailure
     */
    private static function apply(MailingList $list, MailingListChanges $changes): MailingList
    {
        try {
            if ($changes->tag !== null) {
                $list = $list->withTag($changes->tag);
            }

            if ($changes->address !== null) {
                $list = $list->withAddress($changes->address);
            }

            if ($changes->members !== null) {
                $list = $list->withMembers($changes->members);
            }
        } catch (InvalidMailingList $error) {
            throw MailingListFailure::rejected($error);
        }

        return $list;
    }

    /**
     * The names of the fields the request mentioned — never their values. A log
     * line is not a copy of the table, and a member list is personal data.
     *
     * @return list<string>
     */
    private static function changedFields(MailingListChanges $changes): array
    {
        $fields = [];

        if ($changes->tag !== null) {
            $fields[] = 'tag';
        }

        if ($changes->address !== null) {
            $fields[] = 'address';
        }

        if ($changes->members !== null) {
            $fields[] = 'members';
        }

        return $fields;
    }
}
