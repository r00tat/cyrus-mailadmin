<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\ListPolicy;
use Mailadmin\Domain\Policy\ResourceNotVisible;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `PATCH /lists/{address}` — change a mailing list (A5).
 *
 * Only `lists` is written, and only `name`, `tag` and `members`. The address
 * is the identity and is not changeable here (spec 4.3, "Identität"), so the
 * `virtual` row that routes the list never needs touching.
 *
 * ## The rule this class exists to keep
 *
 * **A list that is saved unchanged is written back byte for byte.** The exact
 * separator the existing rows use was never captured, so this package must
 * never re-punctuate a column it was not asked to change.
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
 * ## No change without its record
 *
 * `lists` is MyISAM: a failing audit entry does not roll the `UPDATE` back.
 * So when the audit entry cannot be written, the row is set back to what was
 * read, the way A3 sets back its rows (U-105). If even that fails, the state
 * is a repair-queue entry.
 *
 * ## Concurrency
 *
 * `If-Match` is accepted, not demanded (architecture 7.1). Sent and stale, the
 * answer is `412`; absent, the write proceeds.
 *
 * Read, write and set-back run under the namespace lock that creating,
 * deleting and the repair retry take, so a deletion cannot slip in between
 * and a set-back cannot overwrite somebody else's change.
 */
final readonly class UpdateMailingList
{
    public const string OPERATION = 'list.update';

    public function __construct(
        private MailingListRepository $repository,
        private DomainStore $domains,
        private AuditWriter $audit,
        private RepairQueue $repairs,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible when the list is in a domain the actor does not manage
     * @throws MailingListFailure when the list is absent, `If-Match` is stale, or a value is refused
     * @throws PersistenceError
     * @throws Throwable when the audit entry cannot be written; the row is set back
     */
    public function handle(
        ActorContext $actor,
        string $address,
        MailingListChanges $changes,
        ?string $ifMatch,
    ): MailingList {
        ListPolicy::requireWrite($actor);

        $updated = $this->domains->withNamespaceLock(function () use ($actor, $address, $changes, $ifMatch): MailingList {
            $stored = MailingListGuard::load($this->repository, $actor, $address);
            MailingListGuard::precondition($stored, $ifMatch);

            $updated = self::apply($stored, $changes);

            $this->repository->replace($updated);

            try {
                $this->audit->record(AuditEntry::change(
                    $actor,
                    self::OPERATION,
                    'list',
                    $stored->address,
                    MailingListView::representation($stored),
                    MailingListView::representation($updated),
                    $stored->domain(),
                ));
            } catch (Throwable $error) {
                $this->setBack($stored, $error);

                throw $error;
            }

            return $updated;
        });

        // No address below `error`: the audit trail holds it.
        $this->logger->info('A mailing list was changed.', [
            'admin' => $actor->username,
            'changed' => self::changedFields($changes),
            'member_count' => $updated->members->count(),
        ]);

        return $updated;
    }

    private function setBack(MailingList $stored, Throwable $cause): void
    {
        $this->logger->error('A mailing list change could not be recorded and is being set back.', [
            'list_address' => $stored->address,
            'reason' => $cause->getMessage(),
        ]);

        try {
            $this->repository->replace($stored);
        } catch (Throwable $error) {
            $this->logger->error('A mailing list change could not be set back.', [
                'list_address' => $stored->address,
                'reason' => $error->getMessage(),
            ]);

            // Guarded: a failing repair queue must not replace the audit
            // failure, which the caller rethrows.
            try {
                $this->repairs->record(new RepairRequest(
                    operation: self::OPERATION,
                    step: 'lists.restore',
                    subject: $stored->address,
                    intended: sprintf('change the list %s and record the change in the audit log', $stored->address),
                    reached: sprintf('the list %s is changed and no audit entry records it', $stored->address),
                    reason: 'the audit entry could not be written and the row could not be set back',
                ));
            } catch (Throwable $repairError) {
                $this->logger->error('The repair entry for a mailing list change could not be written.', [
                    'list_address' => $stored->address,
                    'reason' => $repairError->getMessage(),
                ]);
            }
        }
    }

    /**
     * @throws MailingListFailure
     */
    private static function apply(MailingList $list, MailingListChanges $changes): MailingList
    {
        try {
            if ($changes->name !== null) {
                $list = $list->withName($changes->name);
            }

            if ($changes->tag !== null) {
                $list = $list->withTag($changes->tag);
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

        if ($changes->name !== null) {
            $fields[] = 'name';
        }

        if ($changes->tag !== null) {
            $fields[] = 'tag';
        }

        if ($changes->members !== null) {
            $fields[] = 'members';
        }

        return $fields;
    }
}
