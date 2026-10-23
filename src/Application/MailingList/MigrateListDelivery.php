<?php

declare(strict_types=1);

namespace Mailadmin\Application\MailingList;

use Mailadmin\Application\Address\AddressStore;
use Mailadmin\Application\Audit\AuditEntry;
use Mailadmin\Application\Audit\AuditWriter;
use Mailadmin\Application\Domain\DomainStore;
use Mailadmin\Domain\Access\ActorContext;
use Mailadmin\Domain\Access\Permission;
use Mailadmin\Domain\Exception\DomainError;
use Mailadmin\Domain\MailingList\MailingList;
use Mailadmin\Domain\Model\EmailAddress;
use Mailadmin\Domain\Model\LocalPart;
use Mailadmin\Domain\Model\Virtual\DestinationList;
use Mailadmin\Domain\Model\Virtual\MailingListRecognition;
use Mailadmin\Domain\Model\Virtual\VirtualRow;
use Mailadmin\Domain\Policy\AccessDenied;
use Mailadmin\Domain\Policy\Guard;
use Mailadmin\Infrastructure\Persistence\Criteria\ListCriteria;
use Mailadmin\Infrastructure\Persistence\MailingListRepository;
use Mailadmin\Infrastructure\Persistence\PersistenceError;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Step 4 of the changeover (spec 6): move the `virtual` rows of the legacy
 * lists from the alias-file path to the pipe transport, or back.
 *
 *  - **Forward:** a legacy row `dest = username = listKey(alias)` becomes
 *    `dest = listKey(alias)@<transport domain>`, `username = ''`.
 *  - **Revert:** the transport row becomes the legacy row again.
 *
 * Which row is which is decided by {@see MailingListRecognition}, exactly as
 * the classifier of `virtual` decides it — no second set of patterns. A row
 * of another type under a list address is never touched.
 *
 * ## What is deliberately left alone
 *
 *  - A list without a `virtual` row is reported (`no virtual entry`), not
 *    created: whether it should receive mail is not this command's call.
 *  - A list address with a recipient delimiter (`+`) is not moved forward. The
 *    transport hands the pipe script `${user}`, the local part without its
 *    extension, so the list would never be found ({@see CreateMailingList}).
 *  - A list that has a legacy and a transport row at once is not touched in
 *    either direction: moving one would leave two identical rows.
 *  - On revert, a list key longer than `virtual.username` holds: the legacy
 *    form would be cut off and route nowhere.
 *
 * ## How it writes
 *
 * One list at a time, under the namespace lock every address use case takes,
 * with the rows read again inside it. Each row is one `UPDATE` with its audit
 * entry in {@see AddressStore::atomically()}. `virtual` is MyISAM, so when the
 * audit entry fails the row is set back by a second `UPDATE`; the list is
 * reported as `failed` and the run goes on with the next. A row the set-back
 * could not reach is logged at `error` — it is in the transport form (or the
 * legacy one), and both deliver while the changeover is in progress.
 *
 * A second run finds nothing to do: idempotent by construction.
 */
final readonly class MigrateListDelivery
{
    public const string MIGRATE = 'list.delivery_migrate';
    public const string REVERT = 'list.delivery_revert';

    /** `virtual.username` is VARCHAR(50) in the legacy schema. */
    public const int USERNAME_LENGTH = 50;

    private const int PAGE_SIZE = 200;

    public function __construct(
        private MailingListRepository $lists,
        private AddressStore $virtual,
        private DomainStore $domains,
        private ListDelivery $delivery,
        private AuditWriter $audit,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AccessDenied     unless the actor is a superuser (or the system)
     * @throws PersistenceError when the lists cannot be read
     */
    public function handle(ActorContext $actor, bool $revert = false, bool $dryRun = false): ListDeliveryMigration
    {
        Guard::permission($actor, Permission::MaintenanceWrite);
        Guard::permission($actor, Permission::ListWrite);

        $outcomes = [];

        foreach ($this->allLists($actor) as $list) {
            $outcomes[] = $this->one($actor, $list, $revert, $dryRun);
        }

        return new ListDeliveryMigration($revert, $dryRun, $outcomes);
    }

    /**
     * Every list, in address order. Only `virtual` is written, so the pages
     * do not shift under the run.
     *
     * @return list<MailingList>
     *
     * @throws PersistenceError
     */
    private function allLists(ActorContext $actor): array
    {
        $all = [];
        $page = 1;

        do {
            $result = $this->lists->list($actor, new ListCriteria($page, self::PAGE_SIZE, 'address'));
            array_push($all, ...$result->items);
            ++$page;
        } while ($page <= $result->pageCount());

        return $all;
    }

    private function one(ActorContext $actor, MailingList $list, bool $revert, bool $dryRun): ListDeliveryOutcome
    {
        try {
            $address = EmailAddress::parseStored(trim($list->address));
        } catch (DomainError) {
            return new ListDeliveryOutcome($list->address, ListDeliveryResult::Skipped, 'not a mail address');
        }

        if (!$revert && str_contains($address->localPart->toString(), LocalPart::RECIPIENT_DELIMITER)) {
            return new ListDeliveryOutcome(
                $list->address,
                ListDeliveryResult::Skipped,
                sprintf("address contains '%s'", LocalPart::RECIPIENT_DELIMITER),
            );
        }

        try {
            return $this->domains->withNamespaceLock(
                fn (): ListDeliveryOutcome => $this->locked($actor, $list->address, $address, $revert, $dryRun),
            );
        } catch (Throwable $error) {
            $this->logger->error('The list delivery of a mailing list could not be changed.', [
                'list_address' => $list->address,
                'reason' => $error->getMessage(),
            ]);

            return new ListDeliveryOutcome($list->address, ListDeliveryResult::Failed, 'the database refused the change');
        }
    }

    /**
     * @throws Throwable
     */
    private function locked(
        ActorContext $actor,
        string $stored,
        EmailAddress $address,
        bool $revert,
        bool $dryRun,
    ): ListDeliveryOutcome {
        $recognition = new MailingListRecognition($this->delivery->transportDomain, [$address->toString()]);

        $rows = $this->virtual->rowsWithAlias($address->toString());

        if ($rows === []) {
            return new ListDeliveryOutcome($stored, ListDeliveryResult::NoVirtualEntry);
        }

        $legacy = [];
        $transport = [];

        foreach ($rows as $row) {
            if ($recognition->isTransportListRow($row, DestinationList::fromLegacyString($row->dest))) {
                $transport[] = $row;
            } elseif ($recognition->isLegacyListRow($row)) {
                $legacy[] = $row;
            }
        }

        if ($legacy === [] && $transport === []) {
            return new ListDeliveryOutcome($stored, ListDeliveryResult::Skipped, 'virtual entry is not a list entry');
        }

        if ($legacy !== [] && $transport !== []) {
            return new ListDeliveryOutcome($stored, ListDeliveryResult::Skipped, 'legacy and transport entries both present');
        }

        $pending = $revert ? $transport : $legacy;

        if ($pending === []) {
            return new ListDeliveryOutcome($stored, ListDeliveryResult::Unchanged);
        }

        $done = $revert ? ListDeliveryResult::Reverted : ListDeliveryResult::Migrated;

        if ($revert && strlen(ListDelivery::listKey($address)) > self::USERNAME_LENGTH) {
            return new ListDeliveryOutcome(
                $stored,
                ListDeliveryResult::Skipped,
                sprintf('list key longer than the %d characters virtual.username holds', self::USERNAME_LENGTH),
            );
        }

        if ($dryRun) {
            return new ListDeliveryOutcome($stored, $done, '', count($pending));
        }

        $changed = 0;

        foreach ($pending as $row) {
            $changed += $this->rewrite($actor, $address, $row, $revert) ? 1 : 0;
        }

        return new ListDeliveryOutcome($stored, $changed > 0 ? $done : ListDeliveryResult::Unchanged, '', $changed);
    }

    /**
     * One row, with its audit entry; set back when the entry fails. `false`
     * when the row was no longer there.
     *
     * @throws Throwable
     */
    private function rewrite(ActorContext $actor, EmailAddress $address, VirtualRow $row, bool $revert): bool
    {
        $alias = EmailAddress::parseStored(trim($row->alias));
        $key = ListDelivery::listKey($alias);
        $dest = $revert ? $key : $this->delivery->destinationFor($alias);
        $username = $revert ? $key : '';
        $after = new VirtualRow($row->alias, $dest, $username, $row->status);

        return $this->virtual->atomically(function () use ($actor, $address, $row, $after, $revert): bool {
            if (!$this->virtual->rewrite($row, $after->dest, $after->username)) {
                // Changed by someone else since it was read under the lock:
                // nothing was written, so there is nothing to record.
                return false;
            }

            try {
                $this->audit->record(AuditEntry::change(
                    $actor,
                    $revert ? self::REVERT : self::MIGRATE,
                    'virtual',
                    $row->alias,
                    before: CreateMailingList::rowRecord($row),
                    after: CreateMailingList::rowRecord($after),
                    domain: $address->domain,
                ));
            } catch (Throwable $error) {
                // MyISAM: the transaction does not take the UPDATE back.
                $this->setBack($after, $row);

                throw $error;
            }

            return true;
        });
    }

    private function setBack(VirtualRow $current, VirtualRow $original): void
    {
        try {
            if ($this->virtual->rewrite($current, $original->dest, $original->username)) {
                return;
            }
        } catch (Throwable $error) {
            $this->logger->error('A virtual row changed by the list delivery changeover could not be set back.', [
                'list_address' => $current->alias,
                'reason' => $error->getMessage(),
            ]);

            return;
        }

        $this->logger->error('A virtual row changed by the list delivery changeover was gone when it was to be set back.', [
            'list_address' => $current->alias,
        ]);
    }
}
