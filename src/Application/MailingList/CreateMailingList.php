<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Application\Address\RowWriter;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Exception\InvalidMailingList;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\ListPolicy;
use Mailadmin\Domain\Repair\RepairQueue;
use Mailadmin\Domain\Repair\RepairRequest;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `POST /lists` — a new mailing list (A5, spec 4.3).
 *
 * A list is two rows: one in `lists` (what the pipe script reads at delivery
 * time) and one in `virtual` that routes the address to the transport domain
 * (`dest = listKey@transportDomain`, `username` empty so that
 * `sender_canonical_maps` does not apply to it).
 *
 * ## Order, and what a failure leaves behind
 *
 * Both tables are MyISAM, so there is no transaction across them. The order
 * follows "so scheitern, dass niemand Mail verliert" (CLAUDE.md):
 *
 * 1. `lists` first — a list row without its `virtual` row receives nothing
 *    and is harmless.
 * 2. `virtual`, with the audit entries.
 * 3. If step 2 fails, its rows are set back the way A3 sets back its own
 *    ({@see RowWriter::restore()}), and the `lists` row is removed again. If
 *    that fails too, a repair entry `list.create`/`lists.remove` says so, and
 *    the original failure travels on.
 *
 * ## Under the namespace lock
 *
 * The lock every address use case takes (`DomainStore::withNamespaceLock()`),
 * not one per list address as the spec names it: it is stricter, and it is
 * what keeps an alias and a list from claiming the same address at the same
 * moment.
 *
 * ## What is checked, in this order
 *
 *  - the address is lower-cased before anything else — every list address in
 *    production is, and the list key keeps the case it is given;
 *  - no recipient delimiter in the local part (`list.address_has_extension`);
 *  - the domain is the actor's (`403` otherwise) and a managed one
 *    (`list.unknown_domain`);
 *  - the address is free: not in `lists`, not in `virtual` or as an account
 *    name, and no list shares its list key (`list.address_taken`).
 *
 * The list-key check can tell a domain master that a list exists in another
 * domain (`a.b@own.test` and `a@b.own.test` share a key); the spec requires
 * the refusal all the same.
 */
final readonly class CreateMailingList
{
    public const string OPERATION = 'list.create';

    public function __construct(
        private MailingListRepository $repository,
        private AddressStore $virtual,
        private DomainStore $domains,
        private ListDelivery $delivery,
        private AuditWriter $audit,
        private RowWriter $rows,
        private RepairQueue $repairs,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $members
     *
     * @throws AccessDenied
     * @throws MailingListFailure when a value is refused or the address is taken
     * @throws PersistenceError
     * @throws Throwable when the `virtual` row or the audit entry cannot be written
     */
    public function handle(
        ActorContext $actor,
        string $name,
        string $tag,
        string $address,
        array $members,
    ): MailingList {
        ListPolicy::requireWrite($actor);

        $list = self::assemble($name, $tag, strtolower(trim($address)), $members);
        $listAddress = MailingListGuard::addressOf($list);

        // The transport hands the pipe script `${user}`, the local part
        // without its extension: with Postfix's `recipient_delimiter = +`,
        // `team+x` would arrive as `team` and the list key would never match.
        // `${mailbox}` does not help either, because `propagate_unmatched_extensions`
        // then delivers `team+x@…` as `key+x`. So a list address has none.
        if (str_contains($listAddress->localPart->toString(), LocalPart::RECIPIENT_DELIMITER)) {
            throw MailingListFailure::addressHasExtension($list->address);
        }

        ListPolicy::requireCreateIn($actor, $listAddress);

        $this->domains->withNamespaceLock(function () use ($actor, $list, $listAddress): void {
            $this->requireFree($listAddress);

            $this->repository->add($list);

            $row = new VirtualRow(
                $list->address,
                $this->delivery->destinationFor($listAddress),
                '',
                VirtualRow::STATUS_ENABLED,
            );

            try {
                $this->virtual->atomically(function () use ($actor, $list, $listAddress, $row): void {
                    $this->virtual->insert($row);

                    $operation = $this->audit->record(AuditEntry::creation(
                        $actor,
                        self::OPERATION,
                        'list',
                        $list->address,
                        after: MailingListView::representation($list),
                        domain: $listAddress->domain,
                    ));

                    $this->audit->record(AuditEntry::creation(
                        $actor,
                        self::OPERATION,
                        'virtual',
                        $row->alias,
                        after: self::rowRecord($row),
                        domain: $listAddress->domain,
                    )->partOf($operation));
                });
            } catch (Throwable $error) {
                $this->rows->restore(self::OPERATION, $list->address, [], [$row], $error);
                $this->removeListRow($listAddress, $row, $error);

                throw $error;
            }
        });

        // No address below `error`: the audit trail holds it.
        $this->logger->info('A mailing list was created.', [
            'admin' => $actor->username,
            'member_count' => $list->members->count(),
        ]);

        return $list;
    }

    /**
     * The `virtual` row as the audit trail records it.
     *
     * @return array{alias: string, dest: string, username: string, status: int}
     */
    public static function rowRecord(VirtualRow $row): array
    {
        return [
            'alias' => $row->alias,
            'dest' => $row->dest,
            'username' => $row->username,
            'status' => $row->status,
        ];
    }

    /**
     * @throws MailingListFailure
     * @throws PersistenceError
     */
    private function requireFree(EmailAddress $address): void
    {
        if (!$this->domains->exists($address->domain)) {
            throw MailingListFailure::unknownDomain($address->domain->toString());
        }

        if ($this->repository->find($address) !== null
            || $this->virtual->addressTaken($address->toString())
            || $this->repository->existsWithKey(ListDelivery::listKey($address))) {
            throw MailingListFailure::addressTaken($address->toString());
        }
    }

    /**
     * Step 3: take the `lists` row back — but only once the `virtual` row is
     * known to be gone. A routing row whose list vanished would bounce mail
     * that the restore's own repair entry is already about.
     */
    private function removeListRow(EmailAddress $address, VirtualRow $row, Throwable $cause): void
    {
        try {
            if ($this->virtual->count($row) > 0) {
                return;
            }

            $this->repository->remove($address);
        } catch (Throwable $error) {
            $this->logger->error('A mailing list row could not be removed after its creation failed.', [
                'list_address' => $address->toString(),
                'reason' => $error->getMessage(),
            ]);

            // Guarded: a failing repair queue must not replace the original
            // failure, which the caller rethrows.
            try {
                $this->repairs->record(new RepairRequest(
                    operation: self::OPERATION,
                    step: 'lists.remove',
                    subject: $address->toString(),
                    intended: sprintf('create the list %s in lists and virtual', $address->toString()),
                    reached: sprintf('the lists row of %s exists without a virtual row and receives nothing', $address->toString()),
                    reason: self::reasonOf($cause),
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
        }
    }

    /**
     * A stable description of why the creation failed — never an exception
     * message, which may carry database details.
     */
    private static function reasonOf(Throwable $cause): string
    {
        return $cause instanceof PersistenceError
            ? 'the virtual row or its audit entry could not be written, and the lists row could not be removed'
            : 'the creation failed, and the lists row could not be removed';
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
