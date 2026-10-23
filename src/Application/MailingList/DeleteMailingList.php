<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Application\Address\AddressReader;
use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Application\Address\RowWriter;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\Virtual\VirtualEntryType;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
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
 * `DELETE /lists/{address}` — remove a mailing list (A5, spec 4.3).
 *
 * ## Order, and what a failure leaves behind
 *
 * 1. **`virtual`**, with the audit entries: every row of the address that the
 *    classifier recognises as a mailing list row — the transport form and the
 *    legacy form alike. From here on no mail reaches the list. A row of
 *    another type under the same address (inconsistent legacy data) stays and
 *    is logged. If this step fails, the rows are set back
 *    ({@see RowWriter::restore()}) and the failure goes to the client.
 * 2. **`lists`.** If that fails, the deletion stands all the same: a `lists`
 *    row without its `virtual` row receives nothing. It becomes a repair entry
 *    `list.delete`/`lists.remove` and an `error` log line, and the client gets
 *    its `204` — the way `DeleteAccount` reports a mailbox it could not remove.
 *    Even a repair queue that refuses the entry does not turn this into a
 *    failure; that is an `error` log line as well.
 *
 * Both steps run under the namespace lock every address use case takes.
 */
final readonly class DeleteMailingList
{
    public const string OPERATION = 'list.delete';

    public function __construct(
        private MailingListRepository $repository,
        private AddressReader $reader,
        private DomainStore $domains,
        private AddressStore $virtual,
        private AuditWriter $audit,
        private RowWriter $rows,
        private RepairQueue $repairs,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AccessDenied
     * @throws ResourceNotVisible when the list is in a domain the actor does not manage
     * @throws MailingListFailure when the list is absent or `If-Match` is stale
     * @throws PersistenceError
     * @throws Throwable when the `virtual` step or its audit entry fails; the rows are set back
     */
    public function handle(ActorContext $actor, string $address, ?string $ifMatch): void
    {
        ListPolicy::requireDelete($actor);

        $this->domains->withNamespaceLock(function () use ($actor, $address, $ifMatch): void {
            $list = MailingListGuard::load($this->repository, $actor, $address);
            MailingListGuard::precondition($list, $ifMatch);

            $listAddress = MailingListGuard::addressOf($list);

            $this->removeRouting($actor, $list, $listAddress);
            $this->removeList($actor, $listAddress);
        });
    }

    /**
     * Step 1.
     *
     * @throws Throwable
     */
    private function removeRouting(ActorContext $actor, MailingList $list, EmailAddress $address): void
    {
        $rows = [];
        $others = 0;

        foreach ($this->reader->withAlias($address->toString())->entries as $entry) {
            if ($entry->type() === VirtualEntryType::MailingList) {
                $rows[] = $entry->row();
            } else {
                ++$others;
            }
        }

        // No address below `error` (here and below): the audit trail holds it.
        if ($others > 0) {
            $this->logger->notice('A mailing list address also has virtual rows of another type; they are kept.', [
                'admin' => $actor->username,
                'row_count' => $others,
            ]);
        }

        /** @var list<VirtualRow> $removed */
        $removed = [];

        try {
            $this->virtual->atomically(function () use ($actor, $list, $address, $rows, &$removed): void {
                foreach ($rows as $row) {
                    // A row somebody else removed meanwhile is gone either way.
                    if ($this->virtual->remove($row)) {
                        $removed[] = $row;
                    }
                }

                $operation = $this->audit->record(AuditEntry::removal(
                    $actor,
                    self::OPERATION,
                    'list',
                    $list->address,
                    before: MailingListView::representation($list),
                    domain: $address->domain,
                ));

                foreach ($removed as $row) {
                    $this->audit->record(AuditEntry::removal(
                        $actor,
                        self::OPERATION,
                        'virtual',
                        $row->alias,
                        before: CreateMailingList::rowRecord($row),
                        domain: $address->domain,
                    )->partOf($operation));
                }
            });
        } catch (Throwable $error) {
            $this->rows->restore(self::OPERATION, $address->toString(), $removed, [], $error);

            throw $error;
        }
    }

    /**
     * Step 2. Never throws: the deletion already stands.
     */
    private function removeList(ActorContext $actor, EmailAddress $address): void
    {
        try {
            if (!$this->repository->remove($address)) {
                $this->logger->notice('A mailing list row was already gone when it was to be removed.', [
                    'admin' => $actor->username,
                ]);
            }
        } catch (Throwable $error) {
            $this->logger->error('A mailing list was deleted and its lists row could not be removed.', [
                'admin' => $actor->username,
                'list_address' => $address->toString(),
                'reason' => $error->getMessage(),
            ]);

            try {
                $this->repairs->record(new RepairRequest(
                    operation: self::OPERATION,
                    step: 'lists.remove',
                    subject: $address->toString(),
                    intended: sprintf('remove the list %s from virtual and lists', $address->toString()),
                    reached: sprintf('the virtual rows of %s are gone; the lists row remains and receives nothing', $address->toString()),
                    reason: 'the database refused to remove the lists row',
                    parameters: [
                        'address' => $address->toString(),
                        'domain' => $address->domain->toString(),
                    ],
                ));
            } catch (Throwable $repairError) {
                $this->logger->error('The repair entry for a mailing list row could not be written.', [
                    'list_address' => $address->toString(),
                    'reason' => $repairError->getMessage(),
                ]);
            }

            return;
        }

        $this->logger->info('A mailing list was deleted.', [
            'admin' => $actor->username,
        ]);
    }
}
